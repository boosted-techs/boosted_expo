<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Carton;
use App\Models\LabelEvent;
use App\Models\LabelGeneration;
use App\Models\LabelMediaProfile;
use App\Models\LabelPrintBatch;
use App\Models\LabelTemplate;
use App\Models\QrIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LabelWorkflow
{
    public function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function source(Carton $carton, LabelTemplate $template, array $context = []): array
    {
        abort_unless($carton->business_id === $template->business_id, 404);
        $carton->loadMissing(['packingJob.product', 'contents.batch', 'shipmentAllocation.shipment.customer']);
        $business = $context['business'] ?? Business::query()->findOrFail($carton->business_id);
        $product = $carton->packingJob?->product;
        abort_unless($product, 422, 'This carton needs a packing product before labels can be prepared.');
        $batch = $carton->contents->count() === 1 ? $carton->contents->first()->batch : null;
        $shipment = $carton->shipmentAllocation?->shipment;
        $profile = $template->media_profile_id ? (isset($context['profiles']) ? $context['profiles']->get($template->media_profile_id) : LabelMediaProfile::query()->where('business_id', $carton->business_id)->findOrFail($template->media_profile_id)) : null;
        $profileData = $profile?->only(['id', 'printer_model', 'protocol', 'dpi', 'width_mm', 'height_mm', 'margin_mm', 'offset_x_mm', 'offset_y_mm', 'media_type', 'orientation']) ?? [];
        if ($profile && ((float) $profile->width_mm !== (float) $template->width_mm || (float) $profile->height_mm !== (float) $template->height_mm || (int) $profile->dpi !== (int) $template->dpi)) {
            throw ValidationException::withMessages(['label' => 'Template dimensions and DPI must match its media profile. Save the template against the correct profile.']);
        }
        $values = [
            'company' => $business->name, 'customer' => $shipment?->customer?->name, 'product' => $product->name, 'sku' => $product->sku,
            'carton' => $carton->carton_code, 'grade' => $carton->grade ? 'Grade: '.$carton->grade : null,
            'batch' => 'Batch: '.($batch?->batch_code ?? 'Mixed batches'),
            'origin' => $batch?->source_reference ? 'Origin: '.$batch->source_reference : null,
            'net' => $carton->net_weight_kg !== null ? 'Net: '.$carton->net_weight_kg.' kg' : null,
            'gross' => $carton->gross_weight_kg !== null ? 'Gross: '.$carton->gross_weight_kg.' kg' : null,
            'packaging' => $carton->packaging_type ? 'Pack: '.$carton->packaging_type : null,
            'flight' => $shipment?->flight_number ? 'Flight: '.$shipment->flight_number : null,
            'awb' => $shipment?->air_waybill ? 'AWB: '.$shipment->air_waybill : null,
            'destination' => $shipment?->destination_airport ? 'To: '.$shipment->destination_airport : null,
        ];
        $contents = $carton->contents->sortBy('id')->map(fn ($line) => [$line->batch_id, (string) $line->quantity, $line->batch?->batch_code])->values()->all();

        return ['carton_id' => $carton->id, 'product_id' => $product->id, 'batch_id' => $batch?->id, 'shipment_id' => $shipment?->id,
            'customer_id' => $shipment?->customer_id, 'values' => $values, 'contents' => $contents,
            'profile' => $profileData, 'template_version' => $template->version];
    }

    public function qr(array $source, LabelTemplate $template, string $scanToken): ?string
    {
        if ($template->qr_level === 'none') {
            return null;
        }
        $id = match ($template->qr_level) {
            'carton' => $source['carton_id'], 'batch' => $source['batch_id'], 'product' => $source['product_id'],
        };
        if (! $id) {
            throw ValidationException::withMessages(['label' => 'Mixed batches need a carton or product QR code.']);
        }
        if ($template->qr_visibility === 'internal' && $template->qr_level === 'carton') {
            return route('labels.scan', $scanToken);
        }
        $identity = QrIdentity::firstOrCreate(['business_id' => $template->business_id, 'level' => $template->qr_level, 'record_id' => $id], ['public_token' => (string) Str::uuid(), 'public_enabled' => false]);
        if ($template->qr_visibility !== 'internal' && ! $identity->public_enabled) {
            throw ValidationException::withMessages(['label' => 'This template uses a public QR. Enable the matching public page explicitly or choose private QR in the template.']);
        }

        return route($template->qr_visibility === 'internal' ? 'labels.trace' : 'qr.public', $identity->public_token);
    }

    public function artwork(Carton $carton, LabelTemplate $template, string $token, array $context = []): array
    {
        abort_unless(in_array($carton->status, ['packed', 'reserved', 'ready', 'shipped', 'delivered'], true) && $carton->packingJob?->status === 'completed', 422, 'Complete packing before preparing stickers.');
        $source = $this->source($carton, $template, $context);
        if (($context['business'] ?? Business::query()->findOrFail($carton->business_id))->label_verification_required && ! $template->include_barcode && ! ($template->qr_level === 'carton' && $template->qr_visibility === 'internal')) {
            throw ValidationException::withMessages(['label' => 'Dispatch verification needs a revision barcode or private carton QR. Select a template with one of those codes.']);
        }
        foreach ($template->required_fields ?? [] as $field) {
            if (! filled($source['values'][$field] ?? null)) {
                throw ValidationException::withMessages(['label' => ZebraLabelRenderer::FIELD_LABELS[$field].' is required for carton '.$carton->carton_code.'. Complete it before printing.']);
            }
        }
        if (($template->customer_id && $source['customer_id'] !== $template->customer_id) || ($template->product_id && $source['product_id'] !== $template->product_id)) {
            throw ValidationException::withMessages(['label' => 'This template is restricted to a different customer or product.']);
        }
        $artwork = app(LabelArtwork::class)->render($source, $template, $token, $this->qr($source, $template, $token));

        return [...$artwork, 'source' => $source];
    }

    public function stale(LabelGeneration $generation, array $context = []): bool
    {
        if (! $generation->source_sha256 || ! $generation->source_snapshot || ! $generation->template || ! $generation->carton || ! $this->validSnapshot($generation)) {
            return true;
        }
        try {
            return ! hash_equals($generation->source_sha256, $this->fingerprint($this->source($generation->carton, $generation->template, $context)));
        } catch (\Throwable) {
            return true;
        }
    }

    public function validSnapshot(LabelGeneration $label): bool
    {
        $snapshot = $label->template_snapshot ?? [];
        $expected = $snapshot['output_sha256'] ?? '';
        unset($snapshot['output_sha256']);
        $png = base64_decode((string) $label->artwork_png, true);

        return is_string($png) && $expected !== '' && hash_equals($expected, $this->fingerprint([
            'template' => $snapshot, 'source' => $label->source_snapshot, 'png' => hash('sha256', $png), 'zpl' => hash('sha256', (string) $label->zpl_snapshot),
        ]));
    }

    public function staleMany(\Illuminate\Database\Eloquent\Collection $labels): Collection
    {
        if ($labels->isEmpty()) {
            return collect();
        }
        $labels->loadMissing(['template', 'carton.packingJob.product', 'carton.contents.batch', 'carton.shipmentAllocation.shipment.customer']);
        $context = ['business' => Business::query()->findOrFail($labels->first()->business_id),
            'profiles' => LabelMediaProfile::query()->where('business_id', $labels->first()->business_id)->whereIn('id', $labels->pluck('template.media_profile_id')->filter()->unique())->get()->keyBy('id')];

        return $labels->mapWithKeys(fn ($g) => [$g->id => $this->stale($g, $context)]);
    }

    public function generate(Business $business, LabelTemplate $template, array $ids, int $copies, string $key, int $actor): LabelPrintBatch
    {
        $ids = array_map('intval', $ids);
        sort($ids);
        $payload = $this->fingerprint(['template' => $template->id, 'cartons' => $ids, 'copies' => $copies]);

        return DB::transaction(function () use ($business, $template, $ids, $copies, $key, $actor, $payload) {
            $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_unless($lockedBusiness->is_active, 404);
            $existing = LabelPrintBatch::query()->where('business_id', $business->id)->where('request_key', $key)->first();
            if ($existing) {
                if ($existing->payload_sha256 !== $payload) {
                    throw ValidationException::withMessages(['request_key' => 'This request was already used for a different label selection.']);
                }

                return $existing;
            }
            $template = LabelTemplate::query()->where('business_id', $business->id)->where('active', true)->lockForUpdate()->findOrFail($template->id);
            if (($template->layout['mode'] ?? '') !== 'bitmap') {
                throw ValidationException::withMessages(['template_id' => 'Open this older template and save it in the preview layout before creating a batch.']);
            }
            if ((int) round($template->width_mm * $template->dpi / 25.4) * (int) round($template->height_mm * $template->dpi / 25.4) * count($ids) > 16000000) {
                throw ValidationException::withMessages(['carton_ids' => 'This artwork is large. Select fewer cartons per batch to keep preview and PDF reliable.']);
            }
            $context = ['business' => $lockedBusiness, 'profiles' => LabelMediaProfile::query()->where('business_id', $business->id)->whereKey($template->media_profile_id)->get()->keyBy('id')];
            $cartons = Carton::query()->where('business_id', $business->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->with(['packingJob.product', 'contents.batch', 'shipmentAllocation.shipment.customer'])->get();
            abort_unless($cartons->count() === count($ids), 404);
            $batch = LabelPrintBatch::create(['business_id' => $business->id, 'request_key' => $key, 'payload_sha256' => $payload, 'copies' => $copies, 'carton_count' => count($ids), 'created_by' => $actor]);
            foreach ($cartons as $carton) {
                $token = (string) Str::ulid();
                $result = $this->artwork($carton, $template, $token, $context);
                $templateSnapshot = [...$template->only(['name', 'width_mm', 'height_mm', 'dpi', 'qr_level', 'qr_visibility', 'include_barcode', 'visible_fields', 'required_fields', 'media_profile_id', 'layout', 'approved_by', 'approved_at']), 'profile' => $result['source']['profile']];
                $templateSnapshot['output_sha256'] = $this->fingerprint(['template' => $templateSnapshot, 'source' => $result['source'], 'png' => hash('sha256', base64_decode($result['png'], true)), 'zpl' => hash('sha256', $result['zpl'])]);
                $generation = LabelGeneration::create([
                    'business_id' => $business->id, 'batch_id' => $batch->id, 'carton_id' => $carton->id, 'template_id' => $template->id,
                    'template_version' => $template->version, 'template_snapshot' => $templateSnapshot,
                    'zpl_snapshot' => $result['zpl'], 'zpl_sha256' => hash('sha256', $result['zpl']),
                    'source_snapshot' => $result['source'], 'source_sha256' => $this->fingerprint($result['source']),
                    'scan_token' => $token, 'artwork_png' => $result['png'], 'generated_by' => $actor,
                ]);
                $this->event($business->id, $generation->id, null, 'generated', 'Batch '.$batch->id.'; '.$copies.' copies requested.', $actor);
            }

            return $batch;
        }, 3);
    }

    public function event(int $business, ?int $generation, ?int $job, string $action, string $reason, int $actor): void
    {
        LabelEvent::create(['business_id' => $business, 'generation_id' => $generation, 'job_id' => $job, 'action' => $action, 'reason' => $reason, 'actor_id' => $actor]);
    }

    public function verify(Business $business, int $id, string $scan, string $reason, int $actor): void
    {
        DB::transaction(function () use ($business, $id, $scan, $reason, $actor) {
            Business::query()->lockForUpdate()->findOrFail($business->id);
            $label = LabelGeneration::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($id);
            $snapshot = $label->template_snapshot ?? [];
            if (empty($snapshot['include_barcode']) && ! (($snapshot['qr_level'] ?? '') === 'carton' && ($snapshot['qr_visibility'] ?? '') === 'internal')) {
                throw ValidationException::withMessages(['scan' => 'This layout has no scannable revision identity. Use a revision barcode or private carton QR template for application verification.']);
            }
            if (! $label->scan_token || ! in_array(trim($scan), [$label->scan_token, 'L'.$label->scan_token, route('labels.scan', $label->scan_token)], true)) {
                throw ValidationException::withMessages(['scan' => 'Scan the revision code printed on this sticker. A carton name alone cannot verify the label version.']);
            }
            if ($this->stale($label)) {
                throw ValidationException::withMessages(['scan' => 'This sticker is stale. Generate and apply a replacement before verification.']);
            }
            $label->update(['verified_at' => now(), 'verified_by' => $actor]);
            $this->event($business->id, $id, null, 'applied_verified', $reason, $actor);
        }, 3);
    }

    public function assertDispatch(Business $business, array $cartonIds): void
    {
        if (! $business->label_verification_required) {
            return;
        }
        $labels = LabelGeneration::query()->where('business_id', $business->id)->whereIn('carton_id', $cartonIds)->whereNotNull('verified_at')->with(['template', 'carton.packingJob.product', 'carton.contents.batch', 'carton.shipmentAllocation.shipment.customer'])->orderByDesc('id')->get()->unique('carton_id')->keyBy('carton_id');
        $stale = $this->staleMany(new \Illuminate\Database\Eloquent\Collection($labels->values()->all()));
        foreach ($cartonIds as $id) {
            if (! $labels->has($id) || $stale[$labels[$id]->id]) {
                throw ValidationException::withMessages(['dispatch' => 'Every carton needs a current, scan-verified sticker before dispatch. Open Print stickers from this shipment to resolve missing or stale labels.']);
            }
        }
    }
}
