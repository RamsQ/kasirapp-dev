<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Validasi Resep: salah satuan adalah kesalahan HPP yang paling mahal dan
 * paling tidak terlihat.
 *
 * HPP = qty_needed x ingredient.buy_price, dan buy_price adalah harga per
 * SATUAN bahan. Kalau kasir menulis 1 (maksudnya 1 gram) untuk bahan yang
 * dihitung per kilogram, HPP naik 1.000 kali tanpa ada yang mendeteksi.
 *
 * Dua lapis perlindungan yang diuji di sini:
 *  1. Satuan disalin ke baris resep saat disimpan, jadi eksplisit dan bisa
 *     ditinjau ulang.
 *  2. Resep dengan HPP melebihi harga jual ditolak, kecuali dikonfirmasi
 *     eksplisit (menu rugi yang memang disengaja).
 */
class UatRecipeUnitValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
    }

    protected function ingredient(string $name, string $unit, float $buyPrice): Ingredient
    {
        return Ingredient::create([
            'name' => $name,
            'unit' => $unit,
            'buy_price' => $buyPrice,
            'stock' => 100,
            'min_stock' => 1,
        ]);
    }

    protected function product(string $title, int $sellPrice): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(['name' => 'RU-'.$title])->id,
            'title' => $title,
            'buy_price' => 0,
            'sell_price' => $sellPrice,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);
    }

    public function test_unit_is_snapshotted_from_the_ingredient(): void
    {
        // Tepung Rp 30/gram (= Rp 30.000/Kg). 100 gram = Rp 3.000, wajar untuk
        // menu Rp 25.000.
        $flour = $this->ingredient('Tepung Units', 'gram', 30);
        $cake = $this->product('Kue Units', 25000);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $cake->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 100]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('gram', Recipe::where('product_id', $cake->id)->firstOrFail()->unit,
            'Satuan bahan harus disalin ke baris resep.');
        $this->assertSame(3000.0, (float) $cake->fresh()->cost_price);
    }

    public function test_wrong_unit_that_inflates_hpp_above_sell_price_is_rejected(): void
    {
        // Biji kopi bersatuan Kg, harga Rp 180.000/Kg.
        $coffee = $this->ingredient('Kopi Units', 'Kg', 180000);
        $drink = $this->product('Kopi Susu Units', 18000);

        // Admin sengaja isi 1 (maksudnya 1 gram) -> HPP 180.000, 10x harga jual.
        $response = $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $drink->id,
                'ingredients' => [['ingredient_id' => $coffee->id, 'qty_needed' => 1]],
            ]);

        $response->assertSessionHas('error');
        $response->assertSessionHas('error', fn ($message) => str_contains(
            $message,
            'satuan takaran salah'
        ) || str_contains($message, 'satuan'));

        $this->assertSame(0, Recipe::where('product_id', $drink->id)->count(),
            'Resep yang ditolak tidak boleh tersimpan.');
        $this->assertSame(0.0, (float) $drink->fresh()->cost_price);
    }

    public function test_intentional_loss_leader_can_be_saved_with_explicit_confirmation(): void
    {
        $coffee = $this->ingredient('Kopi Rugi', 'Kg', 180000);
        $drink = $this->product('Promo Rugi', 18000);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $drink->id,
                'ingredients' => [['ingredient_id' => $coffee->id, 'qty_needed' => 1]],
                'confirm_loss_leader' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Recipe::where('product_id', $drink->id)->count(),
            'Menu rugi yang dikonfirmasi harus boleh disimpan.');
        $this->assertSame(180000.0, (float) $drink->fresh()->cost_price);
    }

    public function test_sensible_recipe_is_accepted_without_confirmation(): void
    {
        $flour = $this->ingredient('Tepung Wajar', 'Kg', 12000);
        $bread = $this->product('Roti Wajar', 25000);

        // 0,2 Kg x 12.000 = 2.400, jauh di bawah harga jual 25.000.
        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $bread->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 0.2]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2400.0, (float) $bread->fresh()->cost_price);
    }

    public function test_recipe_exactly_at_sell_price_is_accepted(): void
    {
        // HPP tepat sama dengan harga jual bukan "rugi", masih boleh.
        $sugar = $this->ingredient('Gula Tepat', 'Kg', 10000);
        $item = $this->product('Pasar Harga', 25000);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $item->id,
                'ingredients' => [['ingredient_id' => $sugar->id, 'qty_needed' => 2.5]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(25000.0, (float) $item->fresh()->cost_price);
    }

    public function test_previous_recipe_is_kept_when_the_new_one_is_rejected(): void
    {
        $flour = $this->ingredient('Tepung Ganti', 'Kg', 12000);
        $sugar = $this->ingredient('Gula Ganti', 'Kg', 90000);
        $bread = $this->product('Roti Ganti', 25000);

        // Resep pertama, wajar.
        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $bread->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 0.2]],
            ])
            ->assertSessionHas('success');
        $this->assertSame(2400.0, (float) $bread->fresh()->cost_price);

        // Resep kedua, salah satuan -> ditolak. Resep lama harus utuh.
        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $bread->id,
                'ingredients' => [['ingredient_id' => $sugar->id, 'qty_needed' => 5]],
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, Recipe::where('product_id', $bread->id)->count(),
            'Penolakan tidak boleh menghapus resep lama.');
        $this->assertSame(2400.0, (float) $bread->fresh()->cost_price,
            'cost_price lama harus tetap utuh.');
    }

    public function test_zero_sell_price_does_not_trigger_the_guard(): void
    {
        // Produk tanpa harga jual (misal giveaway) tidak boleh diblokir.
        $flour = $this->ingredient('Tepung Free', 'Kg', 50000);
        $free = Product::create([
            'category_id' => Category::firstOrCreate(['name' => 'RU-Free'])->id,
            'title' => 'Gratis',
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $free->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 1]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_recipe_page_shows_the_stored_unit(): void
    {
        $flour = $this->ingredient('Tepung Tampil', 'gram', 30);
        $cake = $this->product('Kue Tampil', 25000);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $cake->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 100]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get(route('recipes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Recipes/Index')
                ->where('products.data.0.recipes.0.unit', 'gram',
                    'Satuan yang dipakai harus tampil di daftar resep.')
            );
    }
}
