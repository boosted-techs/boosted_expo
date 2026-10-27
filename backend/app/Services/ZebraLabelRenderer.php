<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Carton;
use App\Models\LabelTemplate;
use App\Models\QrIdentity;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ZebraLabelRenderer
{
    public const FIELD_LABELS = [
        'company' => 'Exporter',
        'customer' => 'Customer',
        'product' => 'Product',
        'sku' => 'Product code',
        'carton' => 'Carton code',
        'grade' => 'Grade',
        'batch' => 'Batch',
        'origin' => 'Farmer / origin',
        'net' => 'Net weight',
        'gross' => 'Gross weight',
        'packaging' => 'Packaging',
        'flight' => 'Flight number',
        'awb' => 'Air waybill',
        'destination' => 'Destination',
    ];

    private function hex(string $text): string
    {
        // ^FH decodes bytes. Hex encoding ALL user-derived input prevents ZPL
        // control commands being injected through product names or notes.
        $bytes = unpack('C*', $text);

        return implode('', array_map(
            fn (int $byte) => sprintf('_%02X', $byte),
            $bytes ?: [],
        ));
    }

    public function make(Carton $carton, LabelTemplate $template): string
    {
        abort_unless($carton->business_id === $template->business_id, 404);
        abort_unless(in_array($carton->status, ['packed', 'reserved', 'ready', 'shipped', 'delivered'], true), 422,
            'Only packed cartons can receive a label.');

        $carton->loadMissing(['packingJob.product', 'contents.batch', 'shipmentAllocation.shipment']);
        $job = $carton->packingJob;
        abort_unless($job && $job->business_id === $carton->business_id, 422,
            'This carton has no completed packing record.');

        $product = $job->product;
        $business = Business::query()->findOrFail($carton->business_id);
        $contents = $carton->contents;
        $batch = $contents->count() === 1 ? $contents->first()->batch : null;
        $batchLabel = $batch?->batch_code ?? 'Mixed batches';
        $shipment = $carton->shipmentAllocation?->shipment;

        $values = [
            'company' => $business->name,
            'customer' => $shipment?->customer?->name,
            'product' => $product->name,
            'sku' => $product->sku,
            'carton' => $carton->carton_code,
            'grade' => $carton->grade ? 'Grade: '.$carton->grade : null,
            'batch' => 'Batch: '.$batchLabel,
            'origin' => $batch?->source_reference ? 'Origin: '.$batch->source_reference : null,
            'net' => 'Net: '.($carton->net_weight_kg ?? '?').' kg',
            'gross' => 'Gross: '.($carton->gross_weight_kg ?? '?').' kg',
            'packaging' => $carton->packaging_type ? 'Pack: '.$carton->packaging_type : null,
            'flight' => $shipment?->flight_number ? 'Flight: '.$shipment->flight_number : null,
            'awb' => $shipment?->air_waybill ? 'AWB: '.$shipment->air_waybill : null,
            'destination' => $shipment?->destination_airport ? 'To: '.$shipment->destination_airport : null,
        ];

        $qrUrl = null;
        if ($template->qr_level !== 'none') {
            $recordId = match ($template->qr_level) {
                'product' => $product->id,
                'batch' => $batch?->id,
                'carton' => $carton->id,
            };
            if ($recordId === null) {
                throw ValidationException::withMessages([
                    'label' => 'This carton contains multiple batches. Select a carton or product QR template instead.',
                ]);
            }
            $identity = QrIdentity::firstOrCreate(
                [
                    'business_id' => $carton->business_id,
                    'level' => $template->qr_level,
                    'record_id' => $recordId,
                ],
                ['public_token' => (string) Str::uuid(), 'public_enabled' => false],
            );
            if ($template->qr_visibility !== 'internal' && ! $identity->public_enabled) {
                throw ValidationException::withMessages([
                    'label' => 'Enable the public '.$template->qr_level.' QR page before generating this label, or choose a template without a QR code.',
                ]);
            }
            $qrUrl = route($template->qr_visibility === 'internal' ? 'labels.trace' : 'qr.public', $identity->public_token);
        }

        $width = (int) round($template->width_mm * $template->dpi / 25.4);
        $height = (int) round($template->height_mm * $template->dpi / 25.4);
        $unit = $template->dpi / 25.4;
        $margin = (int) round(2 * $unit);
        $font = (int) round(3 * $unit);
        $lineHeight = (int) round(5 * $unit);
        $bottomReserve = $template->include_barcode ? (int) round(13 * $unit) : 0;
        $maxY = $height - $margin - $bottomReserve;
        $textWidth = max((int) round(25 * $unit),
            $width - 2 * $margin - ($qrUrl ? (int) round(32 * $unit) : 0)
        );
        $lines = ['^XA', '^CI28', '^PW'.$width, '^LL'.$height, '^LH0,0'];
        $y = $margin;

        foreach ($template->visible_fields as $field) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($y + $lineHeight > $maxY) {
                throw ValidationException::withMessages([
                    'label' => 'This label is too small for its selected fields. Choose fewer fields or a taller label.',
                ]);
            }
            $data = $this->hex(mb_substr((string) $value, 0, 100));
            $lines[] = '^FO'.$margin.','.$y.'^A0N,'.$font.','.$font
                .'^FB'.$textWidth.',1,0,L,0^FH_^FD'.$data.'^FS';
            $y += $lineHeight;
        }

        if ($qrUrl !== null) {
            $qrX = $width - (int) round(30 * $unit);
            $qrY = $margin;
            $magnification = $template->dpi === 300 ? 5 : 3;
            $lines[] = '^FO'.$qrX.','.$qrY.'^BQN,2,'.$magnification
                .'^FH_^FDLA,'.$this->hex($qrUrl).'^FS';
        }

        if ($template->include_barcode) {
            $barY = $height - (int) round(13 * $unit);
            $barHeight = (int) round(8 * $unit);
            $module = $template->width_mm >= 95 ? 2 : 1;
            $lines[] = '^FO'.$margin.','.$barY.'^BY'.$module.',2,'.$barHeight
                .'^BCN,'.$barHeight.',Y,N,N^FH_^FD'.$this->hex($carton->carton_code).'^FS';
        }
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }
}
