<?php

namespace Tests\Feature\Uat;

use App\Exports\RecipeTemplateExport;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * UAT Template Resep: template Excel harus menampilkan satuan bahan.
 *
 * qty_needed memakai satuan yang sama dengan satuan buy_price bahan. Kalau
 * satuan tidak terlihat, HPP bisa meleset 1.000 kali tanpa ada yang menyadari.
 */
class UatRecipeTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function buildSpreadsheet(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $binary = Excel::raw(new RecipeTemplateExport, ExcelFormat::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'resep').'.xlsx';
        file_put_contents($path, $binary);

        $spreadsheet = IOFactory::load($path);
        @unlink($path);

        return $spreadsheet;
    }

    public function test_template_has_a_unit_column_and_fills_it_per_row(): void
    {
        $category = Category::create(['name' => 'TPL']);
        Product::create([
            'category_id' => $category->id, 'title' => 'Kue',
            'buy_price' => 0, 'sell_price' => 20000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        Ingredient::create(['name' => 'Tepung', 'buy_price' => 12000, 'stock' => 10, 'unit' => 'gram']);
        Ingredient::create(['name' => 'Gula', 'buy_price' => 5000, 'stock' => 10, 'unit' => 'kg']);

        $spreadsheet = $this->buildSpreadsheet();
        $main = $spreadsheet->getSheet(0);

        $headings = [];
        for ($column = 1; $column <= 5; $column++) {
            $headings[] = (string) $main->getCell([$column, 1])->getValue();
        }

        $this->assertStringContainsString('QTY', $headings[3]);
        $this->assertStringContainsString('SATUAN', $headings[4],
            'Template harus punya kolom satuan agar kasir tahu satuan qty yang dipakai.');

        // Kolom satuan terisi otomatis dengan rumus yang menunjuk ke DataRef.
        $unitFormula = (string) $main->getCell('D2')->getValue();
        $this->assertStringContainsString('VLOOKUP', $unitFormula);
        $this->assertStringContainsString('DataRef', $unitFormula);

        $ref = $spreadsheet->getSheetByName('DataRef');
        $this->assertNotNull($ref, 'Sheet referensi harus dibuat.');

        $reference = [];
        for ($row = 1; $row <= 2; $row++) {
            $reference[(string) $ref->getCell("C{$row}")->getValue()] =
                (string) $ref->getCell("D{$row}")->getValue();
        }

        $this->assertSame(['Gula' => 'kg', 'Tepung' => 'gram'], $reference,
            'Setiap bahan harus membawa satuannya ke sheet referensi.');
    }

    public function test_template_survives_an_ingredient_without_a_unit(): void
    {
        Category::create(['name' => 'TPL2']);
        Ingredient::create(['name' => 'Bahan Tanpa Satuan', 'buy_price' => 1000, 'stock' => 1, 'unit' => null]);

        $spreadsheet = $this->buildSpreadsheet();
        $ref = $spreadsheet->getSheetByName('DataRef');

        $this->assertNotNull($ref);
        $this->assertSame('Bahan Tanpa Satuan', (string) $ref->getCell('C1')->getValue());
        $this->assertSame('', (string) $ref->getCell('D1')->getValue(),
            'Satuan kosong harus jadi string kosong agar file tetap valid.');
    }

    public function test_import_still_matches_ingredients_by_plain_name(): void
    {
        // Kolom satuan ditambahkan sebagai kolom ke-5, jadi nama bahan di
        // kolom ke-3 harus tetap polos (tanpa "(satuan)") agar VLOOKUP nama
        // di RecipeImport tidak gagal.
        $category = Category::create(['name' => 'TPL3']);
        Ingredient::create(['name' => 'Tepung Terigu', 'buy_price' => 12000, 'stock' => 10, 'unit' => 'gram']);

        $spreadsheet = $this->buildSpreadsheet();
        $ref = $spreadsheet->getSheetByName('DataRef');

        $this->assertSame('Tepung Terigu', (string) $ref->getCell('C1')->getValue(),
            'Dropdown harus berisi nama polos supaya pencarian Ingredient::where(name) tetap cocok.');
    }
}
