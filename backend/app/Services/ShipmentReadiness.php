<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessOperatingPolicy;
use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use App\Models\ShipmentRequirement;
use App\Models\StaffPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ShipmentReadiness
{
    public function manifest(ExportShipment $shipment): string
    {
        $cartons = $shipment->allocations()->with('carton.contents')->orderBy('carton_id')->get()->map(fn ($a) => ['id' => $a->carton_id, 'weights' => $a->carton?->only(['net_weight_kg', 'gross_weight_kg']), 'contents' => $a->carton?->contents->sortBy('batch_id')->map(fn ($c) => $c->only(['batch_id', 'quantity']))->values()->all()])->all();

        return hash('sha256', json_encode(['shipment' => $shipment->only(['id', 'business_id', 'customer_id', 'sales_order_id', 'transport_mode', 'origin_country', 'destination_country', 'origin_airport', 'destination_airport', 'air_waybill', 'flight_number', 'carrier', 'scheduled_departure_at']), 'cartons' => $cartons], JSON_THROW_ON_ERROR));
    }

    public function setDefaults(Business $business, array $kinds, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $kinds, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($business->id, 'exports');
            $policy = BusinessOperatingPolicy::query()->firstOrCreate(['business_id' => $business->id], ['updated_by' => $actor]);
            $before = $policy->required_export_documents ?? [];
            $policy->update(['required_export_documents' => array_values(array_unique($kinds)), 'updated_by' => $actor]);
            app(PlatformAccess::class)->audit($business->id, $actor, 'shipment.document_defaults_changed', $reason, ['required_export_documents' => $before], ['required_export_documents' => $policy->required_export_documents]);
        }, 3);
    }

    public function require(ExportShipment $shipment, array $data, int $actor): ShipmentRequirement
    {
        return DB::transaction(function () use ($shipment, $data, $actor) {
            app(OrderOperations::class)->lockForWork($shipment->business_id, 'exports');
            $shipment = ExportShipment::query()->where('business_id', $shipment->business_id)->lockForUpdate()->findOrFail($shipment->id);
            abort_unless(in_array($shipment->status, ['draft', 'ready'], true), 422, 'Requirements cannot be changed after dispatch or cancellation.');
            if (! empty($data['staff_person_id'])) {
                StaffPerson::query()->where('business_id', $shipment->business_id)->where('active', true)->findOrFail($data['staff_person_id']);
            }
            $record = ShipmentRequirement::query()->updateOrCreate(['business_id' => $shipment->business_id, 'shipment_id' => $shipment->id, 'document_kind' => $data['document_kind']], ['file_required' => $data['file_required'] ?? true, 'staff_person_id' => $data['staff_person_id'] ?? null, 'due_on' => $data['due_on'] ?? null, 'status' => 'required', 'reason' => $data['reason'], 'created_by' => $actor, 'waived_by' => null, 'waived_at' => null, 'waiver_reason' => null]);
            app(PlatformAccess::class)->audit($shipment->business_id, $actor, 'shipment.requirement_set', $data['reason'], [], ['shipment_id' => $shipment->id, 'requirement_id' => $record->id, 'kind' => $record->document_kind, 'file_required' => $record->file_required]);

            return $record;
        }, 3);
    }

    public function waive(ShipmentRequirement $requirement, string $reason, int $actor): void
    {
        DB::transaction(function () use ($requirement, $reason, $actor) {
            app(OrderOperations::class)->lockForWork($requirement->business_id, 'exports');
            $requirement = ShipmentRequirement::query()->where('business_id', $requirement->business_id)->lockForUpdate()->findOrFail($requirement->id);
            $shipment = ExportShipment::query()->where('business_id', $requirement->business_id)->lockForUpdate()->findOrFail($requirement->shipment_id);
            abort_unless(in_array($shipment->status, ['draft', 'ready'], true), 422, 'Cannot waive a requirement after dispatch or cancellation.');
            $requirement->update(['status' => 'waived', 'waived_by' => $actor, 'waived_at' => now(), 'waiver_reason' => $reason]);
            app(PlatformAccess::class)->audit($requirement->business_id, $actor, 'shipment.requirement_waived', $reason, [], ['shipment_id' => $shipment->id, 'requirement_id' => $requirement->id]);
        }, 3);
    }

    public function report(ExportShipment $shipment, ?string $departureOn = null): array
    {
        $business = Business::query()->findOrFail($shipment->business_id);
        $date = $departureOn ?? ($shipment->actual_departure_at?->copy()->timezone($business->timezone)->toDateString() ?? now($business->timezone)->toDateString());
        $hash = $this->manifest($shipment);
        $defaults = BusinessOperatingPolicy::query()->where('business_id', $shipment->business_id)->first()?->required_export_documents ?? [];
        $specific = ShipmentRequirement::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->with('person')->get()->keyBy('document_kind');
        $proof = in_array($shipment->status, ['dispatched', 'delivered'], true) ? $shipment->events()->where('action', 'dispatched')->latest('id')->first()?->details['document_readiness'] ?? null : null;
        $kinds = $proof ? array_column($proof['documents'], 'kind') : array_unique([...$defaults, ...$specific->keys()->all()]);
        $rows = [];
        foreach ($kinds as $kind) {
            $requirement = $specific[$kind] ?? null;
            $frozen = $proof ? collect($proof['documents'])->firstWhere('kind', $kind) : null;
            $check = ExportClearanceCheck::query()->where('business_id', $shipment->business_id)->where('shipment_id', $shipment->id)->where('document_kind', $kind)->when($frozen, fn ($q) => $q->whereKey($frozen['check_id']))->latest('id')->first();
            $reason = null;
            $waived = $requirement?->status === 'waived';
            if (! $waived) {
                if (! $check) {
                    $reason = 'No source document submitted.';
                } elseif ($check->status !== 'internally_checked') {
                    $reason = 'The latest source document is '.$check->status.'.';
                } elseif (! $check->manifest_hash || ! hash_equals($check->manifest_hash, $hash)) {
                    $reason = 'The document review does not match this shipment manifest.';
                } elseif ($check->valid_until && $check->valid_until->toDateString() < $date) {
                    $reason = 'The document expires before the dispatch date.';
                } elseif (($requirement?->file_required ?? true) && ! $check->file_path) {
                    $reason = 'The original supporting document is missing.';
                } elseif ($check->file_path && (! Storage::disk('local')->exists($check->file_path) || ! hash_equals($check->file_sha256, hash_file('sha256', Storage::disk('local')->path($check->file_path))))) {
                    $reason = 'The supporting file is missing or changed.';
                } elseif ($kind === 'air_waybill' && trim((string) $shipment->air_waybill) !== trim((string) $check->external_reference)) {
                    $reason = 'The air waybill reference does not match the shipment.';
                }
            }
            $rows[] = ['kind' => $kind, 'requirement' => $requirement, 'check' => $check, 'waived' => $waived, 'satisfied' => $waived || $reason === null, 'reason' => $reason];
        }

        return ['rows' => $rows, 'ready' => collect($rows)->every(fn ($row) => $row['satisfied']), 'manifest_hash' => $hash, 'checked_for' => $date];
    }

    public function assertReady(ExportShipment $shipment, string $date): array
    {
        $report = $this->report($shipment, $date);
        $missing = collect($report['rows'])->filter(fn ($r) => ! $r['satisfied']);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['dispatch' => 'Required shipment documents are not ready: '.$missing->map(fn ($r) => (ExportClearanceRegister::TYPES[$r['kind']] ?? $r['kind']).' — '.$r['reason'])->implode(' ')]);
        }

        return ['manifest_hash' => $report['manifest_hash'], 'checked_for' => $date, 'documents' => collect($report['rows'])->map(fn ($r) => ['kind' => $r['kind'], 'check_id' => $r['check']?->id, 'file_sha256' => $r['check']?->file_sha256, 'requirement_id' => $r['requirement']?->id, 'waived' => $r['waived'], 'waived_by' => $r['requirement']?->waived_by])->all()];
    }
}
