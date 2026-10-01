<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Kitchen Display: order lama tidak boleh memenuhi layar.
 *
 * MASALAH ASAL: menyalakan Kitchen Display langsung menampilkan seluruh
 * riwayat order. Order yang dibuat berminggu-minggu lalu, saat KDS masih
 * mati, ikut memenuhi layar. Dapur lalu mengejar order yang sudah basi, dan
 * kasir mengira semua pesanan itu masih menunggu.
 *
 * Penyebabnya berlapis:
 *  1. Kolom `kitchen_status` ber-default 'pending'. Jalur yang lupa menulis
 *     status ini membuat order yang tidak pernah masuk dapur tetap terlihat
 *     sebagai "menunggu dimasak".
 *  2. Tidak ada catatan kapan KDS terakhir dinyalakan, jadi tidak ada cara
 *     memisahkan order yang sudah dimasak dari order yang belum.
 *
 * Yang diuji di sini:
 *  - Menyalakan KDS menutup order lama yang menggantung.
 *  - Waktu KDS dinyalakan dicatat dan dipakai sebagai batas bawah.
 *  - Order yang dibuat SEJAK itu tetap tampil.
 *  - Menyalakan ulang KDS tidak menghapus apa pun dari pembukuan.
 */
class UatKitchenDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['settings.cgos', 'settings.update', 'kitchen-display.index'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo(['settings.cgos', 'kitchen-display.index']);

        $category = Category::create(['name' => 'Makanan']);
        $this->product = Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'type' => 'single',
            'sell_price' => 25000,
            'buy_price' => 12000,
            'stock' => 100,
        ]);

        $this->setting = Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
            'kitchen_display_enabled_at' => null,
        ]);
    }

    protected function order(string $invoice, $createdAt, string $kitchenStatus = 'pending'): Transaction
    {
        $transaction = new Transaction([
            'cashier_id' => $this->admin->id,
            'invoice' => $invoice,
            'cash' => 25000,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 25000,
            'tax_amount' => 0,
            'grand_total' => 25000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $transaction->forceFill([
            'kitchen_status' => $kitchenStatus,
            'created_at' => $createdAt,
        ])->save();

        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 25000,
            'buy_price' => 12000,
        ]);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(array $override = []): array
    {
        return array_merge([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'both',
        ], $override);
    }

    public function test_enabling_kitchen_display_records_the_time_and_closes_stale_orders(): void
    {
        // Tiga order lama, semuanya menggantung di dapur.
        $this->order('LAMA-1', now()->subDays(30));
        $this->order('LAMA-2', now()->subDays(7));
        $this->order('LAMA-3', now()->subDay());

        $this->assertSame(3, Transaction::whereIn('kitchen_status', ['pending', 'preparing', 'ready'])->count());

        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => true]))
            ->assertRedirect();

        $this->assertNotNull($this->setting->fresh()->kitchen_display_enabled_at,
            'Waktu KDS dinyalakan harus tercatat, tanpa itu tidak ada batas bawah untuk layar.');
        $this->assertSame(0, Transaction::whereIn('kitchen_status', ['pending', 'preparing', 'ready'])->count(),
            'Order lama yang menggantung harus ditutup saat KDS dinyalakan.');
    }

    public function test_kitchen_display_does_not_show_orders_created_before_it_was_enabled(): void
    {
        $this->order('SEBELUM-1', now()->subDays(2));
        $this->order('SEBELUM-2', now()->subHour());

        // Nyalakan KDS, lalu buat order yang benar-benar setelahnya.
        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => true]));

        $this->order('SESUDAH-1', now()->addMinute());
        $this->order('SESUDAH-2', now()->addMinutes(2), 'preparing');

        $invoices = collect(
            $this->actingAs($this->admin)->getJson(route('kitchen.orders'))->json('orders')
        )->pluck('invoice')->all();

        $this->assertSame(['SESUDAH-1', 'SESUDAH-2'], $invoices,
            'Hanya order yang dibuat sejak KDS dinyalakan yang boleh tampil. '
            .'Order sebelumnya tidak pernah masuk dapur.');
    }

    public function test_re_enabling_kitchen_display_still_hides_previous_orders(): void
    {
        $this->order('LAMA-1', now()->subDays(5));

        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => true]));
        $this->order('ANTAR-1', now()->addMinute());

        // Matikan lagi.
        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => false]));

        $this->assertSame(1, Transaction::where('kitchen_status', 'pending')->count(),
            'Order yang masih menggantung harus dibiarkan apa adanya saat KDS dimatikan, '
            .'supaya tidak hilang kalau dinyalakan lagi sebentar kemudian.');

        // Nyalakan lagi.
        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => true]));

        $invoices = collect(
            $this->actingAs($this->admin)->getJson(route('kitchen.orders'))->json('orders')
        )->pluck('invoice')->all();

        $this->assertNotContains('LAMA-1', $invoices);
        $this->assertNotContains('ANTAR-1', $invoices,
            'Setelah dinyalakan ulang, layar mulai dari nol. Order yang tidak pernah '
            .'ditandai selesai di dapur tidak boleh muncul sebagai pekerjaan baru.');
    }

    public function test_turning_kitchen_display_on_never_deletes_order_data(): void
    {
        $this->order('AMAN-1', now()->subDays(10));
        $this->order('AMAN-2', now()->subDays(1));

        $this->actingAs($this->admin)
            ->post(route('settings.update'), $this->settingsPayload(['kitchen_display_enabled' => true]));

        $this->assertSame(2, Transaction::count(),
            'Menyalakan KDS hanya boleh mengubah status dapur, tidak boleh menghapus transaksi.');
        $this->assertDatabaseHas('transactions', ['invoice' => 'AMAN-1']);
        $this->assertDatabaseHas('transactions', ['invoice' => 'AMAN-2']);
    }

    public function test_kitchen_display_without_enabled_at_does_not_filter(): void
    {
        // Instalasi lama: belum pernah menyalakan KDS lewat UI, jadi tidak ada
        // batas bawah yang bisa dipakai.
        $this->order('SEJAK-SELALU-1', now()->subDays(3));

        $this->setting->update([
            'kitchen_display_enabled' => true,
            'kitchen_display_enabled_at' => null,
        ]);

        $invoices = collect(
            $this->actingAs($this->admin)->getJson(route('kitchen.orders'))->json('orders')
        )->pluck('invoice')->all();

        $this->assertContains('SEJAK-SELALU-1', $invoices,
            'Tanpa catatan waktu, tidak ada data yang bisa jadi acuan. '
            .'Menyembunyikan order secara diam-diam lebih buruk daripada layar berantakan.');
    }
}
