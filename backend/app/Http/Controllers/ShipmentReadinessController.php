<?php

namespace App\Http\Controllers;

use App\Models\ExportClearanceCheck;
use App\Models\ExportShipment;
use App\Models\ShipmentRequirement;
use App\Services\ExportClearanceRegister;
use App\Services\OrderOperations;
use App\Services\PlatformAccess;
use App\Services\ShipmentReadiness;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentReadinessController extends Controller
{
    private function check(Request $r): void
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'documents.requirements.manage'), 403);
    }

    public function defaults(Request $r, ShipmentReadiness $readiness): RedirectResponse
    {
        $this->check($r);
        $data = $r->validate(['kinds' => ['nullable', 'array', 'max:10'], 'kinds.*' => ['required', Rule::in(array_keys(ExportClearanceRegister::TYPES))], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $readiness->setDefaults($r->attributes->get('activeBusiness'), $data['kinds'] ?? [], $data['reason'], $r->user()->id);

        return back()->with('status', 'Business document defaults saved with audit evidence. Active shipment requirements are checked again at dispatch.');
    }

    public function store(Request $r, int $shipment, ShipmentReadiness $readiness): RedirectResponse
    {
        $this->check($r);
        $shipment = ExportShipment::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($shipment);
        $data = $r->validate(['document_kind' => ['required', Rule::in(array_keys(ExportClearanceRegister::TYPES))], 'file_required' => ['required', 'boolean'], 'staff_person_id' => ['nullable', 'integer', 'min:1'], 'due_on' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $readiness->require($shipment, $data, $r->user()->id);

        return back()->with('status', 'Shipment document requirement recorded.');
    }

    public function waive(Request $r, int $requirement, ShipmentReadiness $readiness): RedirectResponse
    {
        $this->check($r);
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $requirement = ShipmentRequirement::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($requirement);
        $readiness->waive($requirement, $data['reason'], $r->user()->id);

        return back()->with('status', 'Business requirement waived with its reason retained. This is not an official exemption.');
    }

    public function evidence(Request $r, int $check): StreamedResponse
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'documents.view'), 403);
        $record = ExportClearanceCheck::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($check);
        $disk = Storage::disk('local');
        abort_unless($record->file_path && str_starts_with($record->file_path, 'shipment-evidence/'.$record->business_id.'/') && $disk->exists($record->file_path) && hash_equals($record->file_sha256, hash_file('sha256', $disk->path($record->file_path))), 404);

        return $disk->download($record->file_path, 'shipment-document-'.$record->id.'.'.pathinfo($record->file_path, PATHINFO_EXTENSION), ['Content-Type' => $record->file_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function revoke(Request $r, int $check): RedirectResponse
    {
        $this->check($r);
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $business = $r->attributes->get('activeBusiness');
        DB::transaction(function () use ($business, $check, $data, $r) {
            app(OrderOperations::class)->lockForWork($business->id, 'exports');
            $record = ExportClearanceCheck::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($check);
            abort_unless($record->status === 'internally_checked', 422, 'Only an internally checked document can be revoked.');
            $record->update(['status' => 'revoked', 'revoked_by' => $r->user()->id, 'revoked_at' => now(), 'revocation_reason' => $data['reason']]);
            app(PlatformAccess::class)->audit($business->id, $r->user()->id, 'shipment.document_revoked', $data['reason'], [], ['shipment_id' => $record->shipment_id, 'check_id' => $record->id]);
        }, 3);

        return back()->with('status', 'Document revoked. Its original submission and review remain in history.');
    }
}
