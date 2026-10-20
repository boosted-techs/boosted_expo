<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductSpreadsheet
{
    private const ALIASES = [
        'sku' => ['sku', 'product code', 'item code', 'stock code', 'code'],
        'name' => ['product', 'product name', 'name', 'item', 'item name', 'commodity', 'produce', 'produce name'],
        'description' => ['description', 'details', 'product description'],
        'base_unit' => ['unit', 'base unit', 'uom', 'unit of measure'],
        'active' => ['active', 'status'],
    ];

    private function cleanHeader(mixed $value): string
    {
        return Str::of((string) $value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function cells($sheet, int $row, int $width): array
    {
        $values = [];
        for ($col = 1; $col <= $width; $col++) {
            $value = $sheet->getCell([$col, $row])->getValue(); // Never evaluate workbook formulas.
            $values[] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $values;
    }

    public function parse(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file->getRealPath());
        try {
            $selectedSheet = null;
            $headingRow = 0;
            $mapping = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $width = min(30, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn()));
                foreach (range(1, min(12, $sheet->getHighestRow())) as $r) {
                    $headings = array_map($this->cleanHeader(...), $this->cells($sheet, $r, $width));
                    $candidate = [];
                    foreach (self::ALIASES as $field => $aliases) {
                        foreach ($headings as $column => $label) {
                            if (in_array($label, $aliases, true)) {
                                $candidate[$field] = $column;
                                break;
                            }
                        }
                    }
                    if (isset($candidate['name'])) {
                        $selectedSheet = $sheet;
                        $headingRow = $r;
                        $mapping = $candidate;
                        break 2;
                    }
                }
            }

            if (! $selectedSheet) {
                throw ValidationException::withMessages([
                    'file' => 'No product-name column was recognised. Download the product template or use headings such as Product, Product Code and Unit.',
                ]);
            }

            $lastRow = $selectedSheet->getHighestRow();
            if ($lastRow <= $headingRow) {
                throw ValidationException::withMessages(['file' => 'There are no product rows below the headings.']);
            }
            if ($lastRow - $headingRow > 1000) {
                throw ValidationException::withMessages(['file' => 'Use an import with no more than 1,000 rows.']);
            }
            $width = min(30, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($selectedSheet->getHighestColumn()));
            $rows = [];
            $issues = [];
            $duplicates = 0;
            foreach (range($headingRow + 1, $lastRow) as $number) {
                $values = $this->cells($selectedSheet, $number, $width);
                $raw = [];
                foreach ($mapping as $field => $column) {
                    $raw[$field] = $values[$column] ?? '';
                }
                $name = trim($raw['name'] ?? '');
                if ($name === '' || in_array(strtolower($name), ['total', 'grand total', 'sub total', 'subtotal'], true)) {
                    continue;
                }
                if (collect($raw)->contains(fn ($value) => str_starts_with((string) $value, '='))) {
                    $issues[] = "Row {$number}: formulas are not accepted; enter plain values.";
                    continue;
                }

                $sku = strtoupper(trim($raw['sku'] ?? ''));
                if ($sku === '') {
                    $sku = 'EXP-'.Str::upper(Str::slug($name));
                }
                $sku = substr($sku, 0, 80);
                $unit = strtolower(trim($raw['base_unit'] ?? 'kg'));
                $unit = match ($unit) {
                    'kgs', 'kilograms', 'kilogram' => 'kg',
                    'grams', 'gram' => 'g',
                    'pieces', 'piece', 'units', 'unit' => 'pcs',
                    'boxes' => 'box',
                    'crates' => 'crate',
                    'bunches' => 'bunch',
                    'sacks' => 'sack',
                    'litres', 'liters', 'liter' => 'litre',
                    default => $unit ?: 'kg',
                };
                if (mb_strlen($name) > 160 || mb_strlen($sku) < 2 || ! in_array($unit, ['kg', 'g', 'tonne', 'pcs', 'box', 'crate', 'bunch', 'sack', 'litre'], true)) {
                    $issues[] = "Row {$number}: check the product name, code and unit.";
                    continue;
                }
                $status = strtolower(trim($raw['active'] ?? ''));
                $active = match ($status) {
                    'inactive', 'archived', 'no', '0', 'false' => false,
                    'active', 'yes', '1', 'true' => true,
                    default => null,
                };
                $key = strtolower($sku);
                if (isset($rows[$key])) {
                    if ($rows[$key]['name'] !== $name) {
                        $issues[] = "Row {$number}: duplicate code {$sku} has different product names.";
                    } else {
                        $duplicates++;
                    }
                    continue;
                }
                $rows[$key] = [
                    'sku' => $sku, 'name' => $name, 'description' => trim($raw['description'] ?? '') ?: null,
                    'base_unit' => $unit, 'active' => $active,
                ];
                if (count($rows) > 500) {
                    $issues[] = 'Only 500 distinct products can be imported at once.';
                    break;
                }
            }

            if (empty($rows) && empty($issues)) {
                $issues[] = 'No product rows were found in the selected worksheet.';
            }

            return [
                'sheet' => $selectedSheet->getTitle(),
                'rows' => array_values($rows),
                'issues' => $issues,
                'duplicates' => $duplicates,
            ];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function workbook(iterable $rows): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Products');
        $heads = ['SKU', 'Product Name', 'Description', 'Unit', 'Status'];
        foreach ($heads as $i => $heading) {
            $sheet->setCellValueExplicit([$i + 1, 1], $heading, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:E1')->getFill()->setFillType('solid')->getStartColor()->setRGB('37664B');
        $sheet->freezePane('A2');
        foreach (['A' => 20, 'B' => 29, 'C' => 50, 'D' => 14, 'E' => 14] as $column => $size) {
            $sheet->getColumnDimension($column)->setWidth($size);
        }
        $number = 2;
        foreach ($rows as $row) {
            $values = [
                $row->sku, $row->name, $row->description ?? '',
                $row->base_unit, $row->active ? 'Active' : 'Archived',
            ];
            foreach ($values as $i => $value) {
                // Explicit string type prevents cells with =,+,-,@ from becoming formulas.
                $sheet->setCellValueExplicit([$i + 1, $number], (string) $value, DataType::TYPE_STRING);
            }
            $number++;
        }

        return $book;
    }

    public function writeToOutput(Spreadsheet $book): void
    {
        (new Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();
    }
}