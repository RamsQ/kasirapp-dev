<?php

namespace Tests\Feature\Uat;

use App\Events\OrderPlaced;
use App\Models\Category;
use App\Models\Hold;
use App\Models\Product;
use App\Models\Table;
use App\Support\SafeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * UAT Ketahanan Notifikasi: notifikasi realtime tidak boleh pernah
 * menggagalkan penyimpanan pesanan.
 *
 * OrderPlaced/OrderDeleted adalah ShouldBroadcast dan project ini memakai
 * QUEUE_CONNECTION=sync, sehingga broadcast berjalan inline di dalam request.
 * Illuminate\Broadcasting\BroadcastEvent tidak punya try/catch, jadi Pusher
 * yang lambat atau mati akan membuat order gagal dan transaksi rollback.
 */
class UatBroadcastResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function orderPayload(Table $table, Product $product, int $qty = 2): array
    {
        return [
            'cart_items' => [['product_id' => $product->id, 'qty' => $qty]],
            'customer_name' => 'Rina',
            'table_id' => $table->id,
            'total' => $product->sell_price * $qty,
        ];
    }

    public function test_public_order_is_still_saved_when_broadcast_fails(): void
    {
        // customerOrder butuh ada user yang bisa menerima pesanan.
        $cashier = \App\Models\User::factory()->create();
        $cashier->assignRole(\Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'super-admin', 'guard_name' => 'web',
        ]));

        // Listener yang mensimulasikan Pusher mati total.
        Event::listen(OrderPlaced::class, function (): void {
            throw new RuntimeException('Pusher error: cURL error 28: Resolving timed out');
        });

        $table = Table::create(['name' => 'Meja 9', 'status' => 'available']);
        $product = Product::create([
            'category_id' => Category::create(['name' => 'BC1'])->id,
            'title' => 'Nasi Goreng', 'buy_price' => 12000, 'sell_price' => 28000,
            'stock' => 10, 'barcode' => 'BC-001',
        ]);

        $response = $this->postJson(route('public.menu.order', ['table_id' => $table->id]), $this->orderPayload($table, $product));
        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Hold::count(), 'Pesanan harus tetap tersimpan walau notifikasi gagal.');
        $this->assertEqualsWithDelta(10.0, (float) $product->fresh()->stock, 0.001, 'Stok hanya direservasi, tidak berkurang.');
    }

    public function test_safe_event_reports_the_failure_instead_of_throwing(): void
    {
        Event::listen(OrderPlaced::class, function (): void {
            throw new RuntimeException('Pusher tidak terjangkau');
        });

        $delivered = SafeEvent::dispatch(new OrderPlaced(1), 'order.placed');

        $this->assertFalse($delivered, 'Helper harus melaporkan gagal, bukan melempar exception.');
    }

    public function test_safe_event_returns_true_when_dispatch_succeeds(): void
    {
        $received = null;
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) use (&$received): void {
            $received = $event;
        });

        $this->assertTrue(SafeEvent::dispatch(new OrderPlaced(7), 'order.placed'));
        $this->assertNotNull($received);
    }

    public function test_broadcast_connection_is_disabled_during_tests(): void
    {
        // env() mengubah string "null" menjadi PHP null, jadi driver aktif
        // adalah NullBroadcaster dan tidak ada koneksi keluar sama sekali.
        $broadcaster = \Illuminate\Support\Facades\Broadcast::driver();

        $this->assertInstanceOf(
            \Illuminate\Broadcasting\Broadcasters\NullBroadcaster::class,
            $broadcaster,
            'Test tidak boleh menembak jaringan sungguhan lewat Pusher.'
        );
    }
}
