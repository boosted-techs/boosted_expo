<?php

namespace App\Services;

use App\Models\LabelTemplate;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use GdImage;
use Illuminate\Validation\ValidationException;

class LabelArtwork
{
    /** ISO/IEC 15417 Code 128 symbol widths, including the seven-element stop. */
    private const CODE128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['label' => $message]);
    }

    private function font(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    }

    private function textWidth(string $text, float $size): int
    {
        $box = imagettfbbox($size, 0, $this->font(), $text);

        return $box[2] - $box[0];
    }

    private function wrap(string $text, float $size, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            if ($this->textWidth($word, $size) > $width) {
                $this->fail('A word is wider than its label field. Increase the field width or reduce the font size.');
            }
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->textWidth($candidate, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    public function render(array $source, LabelTemplate $template, string $token, ?string $qrUrl): array
    {
        $profile = $source['profile'];
        $unit = $template->dpi / 25.4;
        $width = (int) round($template->width_mm * $unit);
        $height = (int) round($template->height_mm * $unit);
        $margin = (float) ($profile['margin_mm'] ?? 2);
        $left = (int) round(($margin + (float) ($profile['offset_x_mm'] ?? 0)) * $unit);
        $top = (int) round(($margin + (float) ($profile['offset_y_mm'] ?? 0)) * $unit);
        $right = $width - (int) round(($margin - (float) ($profile['offset_x_mm'] ?? 0)) * $unit);
        $bottom = $height - (int) round(($margin - (float) ($profile['offset_y_mm'] ?? 0)) * $unit);
        if ($left < 0 || $top < 0 || $right > $width || $bottom > $height || $right <= $left || $bottom <= $top) {
            $this->fail('Media margins and offsets would clip the sticker. Correct the media profile.');
        }
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $rects = [];
        $occupy = function (int $x, int $y, int $w, int $h) use (&$rects, $left, $top, $right, $bottom): void {
            if ($x < $left || $y < $top || $x + $w > $right || $y + $h > $bottom) {
                $this->fail('The selected text, logo or code does not fit this media. Adjust the layout or select larger stickers.');
            }
            foreach ($rects as [$rx, $ry, $rw, $rh]) {
                if ($x < $rx + $rw && $x + $w > $rx && $y < $ry + $rh && $y + $h > $ry) {
                    $this->fail('Label fields overlap. Adjust their positions before printing.');
                }
            }
            $rects[] = [$x, $y, $w, $h];
        };
        $qrReserve = $qrUrl ? (int) round(32 * $unit) : 0;
        if ($qrUrl) {
            $size = (int) round(28 * $unit);
            $occupy($right - $size, $top, $size, $size);
            $png = (new PngWriter)->write(new QrCode(data: $qrUrl, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: $size - 16, margin: 8))->getString();
            $qr = imagecreatefromstring($png);
            if (imagesx($qr) > $size || imagesy($qr) > $size) {
                $this->fail('QR content is too large for this code area.');
            }
            imagecopy($image, $qr, $right - $size, $top, 0, 0, imagesx($qr), imagesy($qr));
            imagedestroy($qr);
        }
        $textBottom = $template->include_barcode ? $bottom - (int) round(14 * $unit) : $bottom;
        $textRight = $right - $qrReserve;
        $y = $top;
        if (! empty($template->layout['logo_png'])) {
            $logo = imagecreatefromstring(base64_decode($template->layout['logo_png'], true));
            if (! $logo) {
                $this->fail('The saved logo is invalid. Upload it again.');
            }
            $lw = (int) round(20 * $unit);
            $lh = (int) round(8 * $unit);
            $occupy($left, $y, $lw, $lh);
            imagecopyresampled($image, $logo, $left, $y, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
            $y += (int) round(10 * $unit);
        }
        foreach ($template->visible_fields as $field) {
            $value = $source['values'][$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $config = $template->layout['fields'][$field] ?? [];
            $fontMm = (float) ($config['font_mm'] ?? $template->layout['font_mm'] ?? 2.5);
            $fontSize = $fontMm * $unit * .75;
            $lineHeight = (int) ceil($fontMm * $unit * 1.3);
            $x = isset($config['x_mm']) ? $left + (int) round($config['x_mm'] * $unit) : $left;
            $fy = isset($config['y_mm']) ? $top + (int) round($config['y_mm'] * $unit) : $y;
            $fw = isset($config['width_mm']) ? (int) round($config['width_mm'] * $unit) : $textRight - $x;
            if ($fw < 1) {
                $this->fail('Text fields need a positive printable width.');
            }
            $lines = $this->wrap((string) $value, $fontSize, $fw);
            if (count($lines) > (int) ($config['lines'] ?? 2)) {
                $this->fail(ZebraLabelRenderer::FIELD_LABELS[$field].' overflows its allowed lines. Increase the field size or reduce its font.');
            }
            $fh = count($lines) * $lineHeight;
            if ($fy + $fh > $textBottom) {
                $this->fail('Text would overlap the barcode or bottom margin. Use fewer fields, smaller fonts or taller media.');
            }
            $occupy($x, $fy, $fw, $fh);
            foreach ($lines as $i => $line) {
                $tw = $this->textWidth($line, $fontSize);
                $align = $config['align'] ?? 'left';
                $tx = $x + ($align === 'right' ? $fw - $tw : ($align === 'center' ? (int) (($fw - $tw) / 2) : 0));
                imagettftext($image, $fontSize, 0, $tx, $fy + $i * $lineHeight + (int) ceil($fontMm * $unit), $black, $this->font(), $line);
            }
            $y = max($y, $fy + $fh + (int) round(.6 * $unit));
        }
        if ($template->include_barcode) {
            $by = $bottom - (int) round(13 * $unit);
            $bh = (int) round(8 * $unit);
            $occupy($left, $by, $right - $left, (int) round(13 * $unit));
            $barcodeField = $template->layout['barcode_field'] ?? 'revision';
            $barcodeValue = $barcodeField === 'revision' ? 'L'.$token : trim((string) ($source['barcode_values'][$barcodeField] ?? $source['values'][$barcodeField] ?? ''));
            if ($barcodeValue === '') {
                $this->fail('The selected barcode field has no value for this carton. Complete it or choose another field.');
            }
            $this->barcode($image, $barcodeValue, $left, $by, $right - $left, $bh, $black, (int) round($unit / 4));
            $caption = $barcodeField === 'revision' ? $source['values']['carton'] : $barcodeValue;
            $fs = 1.9 * $unit * .75;
            if ($this->textWidth($caption, $fs) > $right - $left) {
                $this->fail('Carton reference is too long for the barcode caption.');
            }
            imagettftext($image, $fs, 0, $left + (int) (($right - $left - $this->textWidth($caption, $fs)) / 2), $bottom - (int) round(1 * $unit), $black, $this->font(), $caption);
        }
        if ((int) ($profile['orientation'] ?? 0) === 180) {
            $rotated = imagerotate($image, 180, $white);
            imagedestroy($image);
            $image = $rotated;
        }
        $bytesPerRow = (int) ceil($width / 8);
        $hex = '';
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $bytesPerRow; $col++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $col * 8 + $bit;
                    if ($x < $width) {
                        $rgb = imagecolorat($image, $x, $row);
                        if ((($rgb >> 16 & 255) + ($rgb >> 8 & 255) + ($rgb & 255)) < 384) {
                            $byte |= 128 >> $bit;
                            imagesetpixel($image, $x, $row, $black);
                        } else {
                            imagesetpixel($image, $x, $row, $white);
                        }
                    }
                }
                $hex .= sprintf('%02X', $byte);
            }
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $tracking = ['gap' => 'Y', 'black_mark' => 'M', 'continuous' => 'N'][$profile['media_type'] ?? 'gap'];
        $count = $bytesPerRow * $height;
        $zpl = "^XA\n^PW{$width}\n^LL{$height}\n^LH0,0\n^MN{$tracking}\n^FO0,0^GFA,{$count},{$count},{$bytesPerRow},{$hex}^FS\n^XZ\n";

        return ['png' => base64_encode($png), 'zpl' => $zpl];
    }

    private function barcode(GdImage $image, string $value, int $x, int $y, int $width, int $height, int $black, int $minimumModule): void
    {
        $codes = [104];
        $checksum = 104;
        foreach (str_split($value) as $i => $char) {
            $code = ord($char) - 32;
            if ($code < 0 || $code > 95) {
                $this->fail('Barcode contains an unsupported character.');
            }
            $codes[] = $code;
            $checksum += ($i + 1) * $code;
        }
        $codes[] = $checksum % 103;
        $codes[] = 106;
        $pattern = implode('', array_map(fn ($code) => self::CODE128[$code], $codes));
        $modules = array_sum(array_map('intval', str_split($pattern))) + 20;
        $module = $minimumModule;
        if ($module < 2 || $width < $modules * $module) {
            $this->fail('The barcode needs wider media for readable modules at this DPI. Use a QR-only template for this size.');
        }
        $x += (int) floor(($width - $modules * $module) / 2) + 10 * $module;
        foreach (str_split($pattern) as $i => $run) {
            $runWidth = (int) $run * $module;
            if ($i % 2 === 0) {
                imagefilledrectangle($image, $x, $y, $x + $runWidth - 1, $y + $height - 1, $black);
            }
            $x += $runWidth;
        }
    }
}
