<?php

namespace App\Exports;

use App\Models\Ingredient;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RecipeTemplateExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    protected $products;

    protected $ingredients;

    public function __construct()
    {
        // Ambil produk tipe single yang aktif
        $this->products = Product::where('type', 'single')
            ->orderBy('title', 'asc')
            ->get(['barcode', 'title']);

        // Ambil semua nama bahan baku BESERTA satuannya. Satuan ikut dibawa
        // karena HPP = qty_needed x buy_price, dan buy_price adalah harga per
        // satuan. Tanpa kolom ini, 100 resep bisa diimpor tanpa sandaran satuan.
        $this->ingredients = Ingredient::orderBy('name', 'asc')
            ->get(['name', 'unit'])
            ->map(fn ($ingredient) => [
                'name' => (string) $ingredient->name,
                'unit' => (string) ($ingredient->unit ?? ''),
            ])
            ->all();
    }

    /**
     * Sediakan 100 baris kosong di sheet utama
     */
    public function collection()
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = ['', '', '', '', ''];
        }

        return collect($data);
    }

    public function headings(): array
    {
        return [
            '1. PILIH PRODUK (KLIK PANAH)',
            '2. BARCODE (OTOMATIS)',
            '3. PILIH BAHAN (KLIK PANAH)',
            '4. QTY',
            '5. SATUAN (OTOMATIS)',
        ];
    }

    public function title(): string
    {
        return 'Template Resep';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'], // Indigo Blue
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $spreadsheet = $event->sheet->getDelegate()->getParent();

                // --- 1. BUAT SHEET REFERENSI (DataRef) ---
                $refSheet = $spreadsheet->createSheet();
                $refSheet->setTitle('DataRef');
                $refSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

                // Isi Data Produk (A=Nama, B=Barcode)
                foreach ($this->products as $index => $p) {
                    $row = $index + 1;
                    $refSheet->setCellValue("A{$row}", trim($p->title));
                    $refSheet->setCellValue("B{$row}", (string) $p->barcode);
                }

                // Isi Data Bahan Baku (C=Nama Bahan, D=Satuan)
                foreach ($this->ingredients as $index => $ingredient) {
                    $row = $index + 1;
                    $refSheet->setCellValue("C{$row}", trim($ingredient['name']));
                    $refSheet->setCellValue("D{$row}", $ingredient['unit']);
                }

                $mainSheet = $event->sheet->getDelegate();
                $prodCount = count($this->products);
                $ingCount = count($this->ingredients);

                // --- 2. TERAPKAN DROPDOWN & RUMUS ---
                for ($i = 2; $i <= 101; $i++) {

                    // Dropdown Produk (Kolom A)
                    if ($prodCount > 0) {
                        $valProd = $mainSheet->getCell("A{$i}")->getDataValidation();
                        $valProd->setType(DataValidation::TYPE_LIST);
                        $valProd->setFormula1('DataRef!$A$1:$A$'.$prodCount);
                        $valProd->setShowDropDown(true);

                        // Rumus VLOOKUP Barcode (Kolom B)
                        $mainSheet->setCellValue("B{$i}", "=IF(A{$i}=\"\",\"\",VLOOKUP(A{$i},DataRef!\$A\$1:\$B\${$prodCount},2,FALSE))");
                    }

                    // Dropdown Bahan Baku (Kolom C)
                    if ($ingCount > 0) {
                        $valIng = $mainSheet->getCell("C{$i}")->getDataValidation();
                        $valIng->setType(DataValidation::TYPE_LIST);
                        $valIng->setFormula1('DataRef!$C$1:$C$'.$ingCount);
                        $valIng->setShowDropDown(true);
                        $valIng->setShowErrorMessage(true);
                        $valIng->setErrorStyle(DataValidation::STYLE_STOP);
                        $valIng->setErrorTitle('Input Salah');

                        // PERBAIKAN: Menggunakan setError() bukan setErrorMessage()
                        $valIng->setError('Bahan tidak terdaftar dalam sistem!');
                    }

                    // Kolom satuan otomatis (VLOOKUP). Qty pada kolom D
                    // harus memakai satuan yang sama dengan satuan bahan.
                    if ($ingCount > 0) {
                        $mainSheet->setCellValue("D{$i}", "=IF(C{$i}=\"\",\"\",VLOOKUP(C{$i},DataRef!\$C\$1:\$D\${$ingCount},2,FALSE))");
                    }
                }
            },
        ];
    }
}
