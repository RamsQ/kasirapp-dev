<?php

namespace Tests\Feature;

use App\Models\AiSession;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiCoachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.provider' => 'gemini',
            'services.ai.requests_per_minute' => 10,
            'services.ai.max_message_length' => 1000,
            'services.gemini.key' => 'test-api-key',
            'services.gemini.model' => 'gemini-test-model',
            'services.groq.key' => null,
        ]);

        Permission::findOrCreate('ai-access', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [['text' => 'Rekomendasi dummy']],
                ]],
            ]),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Rekomendasi dummy']]],
            ]),
        ]);
    }

    public function test_user_with_permission_can_open_ai_coach(): void
    {
        $user = $this->userWithAiAccess();

        $this->actingAs($user)
            ->get(route('ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Ai/Consultation')
                ->where('aiProvider', 'Gemini')
                ->where('aiModel', 'gemini-test-model')
                ->where('aiConfigured', true)
                ->where('aiMaxMessageLength', 1000)
                ->where('auth.permissions.ai-access', true)
            );
    }

    public function test_guest_cannot_open_ai_coach(): void
    {
        $this->get(route('ai.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('ai.index'))
            ->assertForbidden();
    }

    public function test_chat_uses_paid_business_data_and_returns_gemini_response(): void
    {
        $user = $this->userWithAiAccess();
        $category = Category::create([
            'name' => 'Kategori AI',
            'description' => 'Kategori pengujian AI',
            'image' => 'category.png',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'AI-001',
            'title' => 'Produk Uji AI',
            'description' => 'Produk untuk pengujian AI',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 3,
        ]);

        $paidTransaction = Transaction::create([
            'cashier_id' => $user->id,
            'invoice' => 'TRX-AI-PAID',
            'cash' => 50000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 50000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);
        $paidTransaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 50000,
        ]);

        Transaction::create([
            'cashier_id' => $user->id,
            'invoice' => 'TRX-AI-PENDING',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 99000,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
        ]);

        Expense::create([
            'user_id' => $user->id,
            'name' => 'Beban operasional',
            'category' => 'Operasional',
            'amount' => 10000,
            'date' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Produk apa yang perlu diprioritaskan?'])
            ->assertOk()
            ->assertJson(['reply' => 'Rekomendasi dummy']);

        $request = null;
        Http::assertSent(function (Request $sentRequest) use (&$request): bool {
            $request = $sentRequest;

            return true;
        });

        $this->assertNotNull($request);
        $prompt = data_get($request->data(), 'input');

        $this->assertStringContainsString('Omzet bersih periode ini (tanpa pajak): Rp 50.000', $prompt);
        $this->assertStringContainsString('Pajak terkumpul: Rp 0', $prompt);
        $this->assertStringContainsString('Pengeluaran operasional: Rp 10.000', $prompt);
        $this->assertStringContainsString('Laba bersih: Rp 40.000', $prompt);
        $this->assertStringContainsString('Jumlah transaksi lunas: 1', $prompt);
        $this->assertStringContainsString('Produk Uji AI: 1 item, Rp 50.000', $prompt);
        $this->assertStringContainsString('Produk apa yang perlu diprioritaskan?', $prompt);
        $this->assertStringNotContainsString('99000', $prompt);

        // Periode dan pembanding harus ikut terkirim supaya AI bisa membaca tren.
        $this->assertStringContainsString('== PERBANDINGAN PERIODE SEBELUMNYA ==', $prompt);
        $this->assertStringContainsString('== STOK KRITIS ==', $prompt);
    }

    public function test_chat_rejects_empty_and_oversized_messages(): void
    {
        $user = $this->userWithAiAccess();

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_chat_is_rate_limited_per_user(): void
    {
        config(['services.ai.requests_per_minute' => 2]);

        $user = $this->userWithAiAccess();

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)
                ->postJson(route('ai.chat'), ['message' => "Analisis {$attempt}"])
                ->assertOk();
        }

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Analisis ketiga'])
            ->assertTooManyRequests()
            ->assertJson([
                'reply' => 'Terlalu banyak permintaan AI. Coba lagi sebentar lagi.',
            ]);
    }

    private function userWithAiAccess(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ai-access');

        return $user;
    }

    private function paidTransaction(User $user, int $amount, string $at): Transaction
    {
        $category = Category::firstOrCreate(['name' => 'Kategori Periode']);
        $product = Product::firstOrCreate(
            ['barcode' => 'AI-PERIODE'],
            [
                'category_id' => $category->id,
                'title' => 'Produk Periode',
                'buy_price' => 20000,
                'sell_price' => 50000,
                'stock' => 50,
            ]
        );

        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'invoice' => 'TRX-PERIODE-'.uniqid(),
            'cash' => $amount,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => $amount,
            'tax_amount' => 0,
            'grand_total' => $amount,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->forceFill(['created_at' => $at])->save();
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $amount,
            'buy_price' => 20000,
        ]);

        return $transaction;
    }

    private function lastPrompt(): string
    {
        $prompt = null;
        Http::assertSent(function (Request $request) use (&$prompt): bool {
            $prompt = data_get($request->data(), 'input');

            return true;
        });

        return (string) $prompt;
    }

    public function test_period_selector_widens_the_analysed_window(): void
    {
        $user = $this->userWithAiAccess();

        // Kemarin 50.000, hari ini 30.000 -> harus terbaca sebagai penurunan.
        $this->paidTransaction($user, 50000, now()->subDay()->toDateTimeString());
        $this->paidTransaction($user, 30000, now()->toDateTimeString());

        // Default: hari ini saja.
        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Bagaimana hari ini?'])
            ->assertOk();

        $todayPrompt = $this->lastPrompt();
        $this->assertStringContainsString('Omzet bersih periode ini (tanpa pajak): Rp 30.000', $todayPrompt);
        // Kemarin 50.000, hari ini 30.000 -> turun 40%.
        $this->assertStringContainsString('-40.0% (turun)', $todayPrompt);
        $this->assertStringNotContainsString('== TREN HARIAN ==', $todayPrompt);

        // 7 hari: mencakup kedua transaksi dan menampilkan tren harian.
        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Tren 7 hari?', 'period' => '7d'])
            ->assertOk();

        $weekPrompt = $this->lastPrompt();
        $this->assertStringContainsString('Omzet bersih periode ini (tanpa pajak): Rp 80.000', $weekPrompt);
        $this->assertStringContainsString('== TREN HARIAN ==', $weekPrompt);
    }

    public function test_last_month_period_ignores_current_month_data(): void
    {
        $user = $this->userWithAiAccess();

        $this->paidTransaction($user, 30000, now()->toDateTimeString());
        $this->paidTransaction($user, 75000, now()->subMonth()->toDateTimeString());

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Bulan lalu?', 'period' => 'last_month'])
            ->assertOk()
            ->assertJson(['period' => 'last_month']);

        $prompt = $this->lastPrompt();
        $this->assertStringContainsString('Omzet bersih periode ini (tanpa pajak): Rp 75.000', $prompt);
        $this->assertStringNotContainsString('Rp 105.000', $prompt);
    }

    public function test_conversation_history_is_carried_to_the_next_question(): void
    {
        $user = $this->userWithAiAccess();

        $first = $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Kenapa laba turun?'])
            ->assertOk()
            ->assertJson(['reply' => 'Rekomendasi dummy']);

        $sessionId = $first->json('session_id');
        $this->assertNotNull($sessionId);

        // Pertanyaan kedua harus membawa giliran pertama.
        $this->actingAs($user)
            ->postJson(route('ai.chat'), [
                'message' => 'Terus bagaimana?',
                'session' => $sessionId,
            ])
            ->assertOk();

        $prompt = $this->lastPrompt();
        $this->assertStringContainsString('== PERCAKAPAN SEBELUMNYA ==', $prompt);
        $this->assertStringContainsString('Kenapa laba turun?', $prompt);
        $this->assertStringContainsString('Rekomendasi dummy', $prompt);

        $session = AiSession::where('user_id', $user->id)->firstOrFail();
        // Dua giliran penuh: tanya-jawab pertama dan tanya-jawab kedua.
        $this->assertCount(4, $session->history);
        $this->assertSame('user', $session->history[0]['role']);
        $this->assertSame('assistant', $session->history[1]['role']);
        $this->assertSame('Kenapa laba turun?', $session->history[0]['content']);
    }

    public function test_session_history_is_capped_to_avoid_prompt_bloat(): void
    {
        $user = $this->userWithAiAccess();
        config(['services.ai.requests_per_minute' => 50]);

        $session = AiSession::create([
            'user_id' => $user->id,
            'period' => 'today',
            'history' => [],
        ]);

        for ($i = 1; $i <= 8; $i++) {
            $session->remember('user', "pertanyaan {$i}");
        }

        $history = $session->fresh()->history;
        $this->assertCount(AiSession::MAX_TURNS, $history);
        $this->assertSame('pertanyaan 8', end($history)['content']);
        $this->assertSame('pertanyaan 3', $history[0]['content'], 'Giliran lama harus dibuang.');
    }

    public function test_user_cannot_read_another_users_session(): void
    {
        $owner = $this->userWithAiAccess();
        $other = $this->userWithAiAccess();

        $foreign = AiSession::create([
            'user_id' => $owner->id,
            'period' => 'today',
            'history' => [['role' => 'user', 'content' => 'Rahasia']],
        ]);

        $this->actingAs($other)
            ->get(route('ai.index', ['session' => $foreign->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history', [])
                ->where('sessionId', fn ($id) => $id !== $foreign->id));
    }

    public function test_invalid_period_falls_back_to_today(): void
    {
        $user = $this->userWithAiAccess();

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Halo', 'period' => 'nonsense'])
            ->assertOk()
            ->assertJson(['period' => 'today']);
    }

    public function test_groq_can_serve_the_business_coach_end_to_end(): void
    {
        config([
            'services.ai.provider' => 'groq',
            'services.groq.key' => 'gsk-test-key',
            'services.groq.model' => 'llama-test-model',
            'services.gemini.key' => null,
        ]);

        $user = $this->userWithAiAccess();

        $this->actingAs($user)
            ->get(route('ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('aiProvider', 'Groq')
                ->where('aiModel', 'llama-test-model')
                ->where('aiConfigured', true));

        $this->actingAs($user)
            ->postJson(route('ai.chat'), ['message' => 'Omzet naik atau turun?'])
            ->assertOk()
            ->assertJson(['reply' => 'Rekomendasi dummy', 'provider' => 'groq']);

        $request = null;
        Http::assertSent(function (Request $sentRequest) use (&$request): bool {
            if (! str_contains($sentRequest->url(), 'api.groq.com')) {
                return false;
            }
            $request = $sentRequest;

            return true;
        });

        $this->assertNotNull($request);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer gsk-test-key'));
        $this->assertStringNotContainsString('gsk-test-key', $request->url());
        // Konteks bisnis harus tetap ikut terkirim meski provider diganti.
        $this->assertStringContainsString('== DATA BISNIS ==', data_get($request->data(), 'messages.1.content'));
    }

    public function test_api_keys_are_never_sent_to_the_frontend(): void
    {
        $user = $this->userWithAiAccess();
        config([
            'services.groq.key' => 'gsk-super-secret',
            'services.gemini.key' => 'gemini-super-secret',
        ]);

        $content = $this->actingAs($user)
            ->get(route('ai.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('gsk-super-secret', $content);
        $this->assertStringNotContainsString('gemini-super-secret', $content);
    }
}
