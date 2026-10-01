<?php

namespace App\Http\Controllers;

use App\Exceptions\AiProviderException;
use App\Models\AiSession;
use App\Services\Ai\AiProviderFactory;
use App\Services\AiBusinessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class AiController extends Controller
{
    public function __construct(
        protected AiProviderFactory $providers,
        protected AiBusinessContextService $context,
    ) {}

    public function index(Request $request): Response
    {
        $session = $this->resolveSession($request->string('session')->toString() ?: null);
        $provider = $this->providers->provider();

        return inertia('Dashboard/Ai/Consultation', [
            'aiProvider' => $provider->label(),
            'aiModel' => $provider->model(),
            'aiConfigured' => $this->providers->anyConfigured(),
            'aiMaxMessageLength' => max(1, (int) config('services.ai.max_message_length', 1000)),
            'periods' => AiBusinessContextService::PERIODS,
            'sessionId' => $session?->id,
            'history' => $session?->history ?? [],
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        if ($request->has('message') && is_string($request->input('message'))) {
            $request->merge(['message' => trim($request->input('message'))]);
        }

        $maxMessageLength = max(1, (int) config('services.ai.max_message_length', 1000));

        $validated = $request->validate([
            'message' => ['required', 'string', "max:{$maxMessageLength}"],
            'period' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'session' => ['nullable', 'integer'],
        ]);

        $period = $this->normalizePeriod($validated['period'] ?? 'today');
        $context = $this->context->build(
            $period,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $prompt = $this->buildPrompt($context, $validated['message']);

        $session = $this->resolveSession($validated['session'] ?? null, $period);

        // Percakapan sebelumnya dikirim sebagai konteks agar pertanyaan lanjutan
        // ("terus bagaimana?") tetap punya arti. Riwayat disimpan di server,
        // tidak diserahkan ke provider sebagai state.
        if ($session && ! empty($session->history)) {
            $prompt = $this->prependHistory($prompt, $session->history);
        }

        try {
            $result = $this->providers->generateResponse($prompt);
        } catch (AiProviderException $exception) {
            return response()->json(
                ['reply' => $exception->getMessage()],
                $exception->status
            );
        }

        if ($session) {
            $session->remember('user', $validated['message']);
            $session->remember('assistant', $result['reply']);
        }

        return response()->json([
            'reply' => $result['reply'],
            'provider' => $result['provider'],
            'session_id' => $session?->id,
            'period' => $period,
            'period_label' => $context['label'],
        ]);
    }

    private function normalizePeriod(?string $period): string
    {
        if ($period && $this->context->isValidPeriod($period)) {
            return $period;
        }

        return 'today';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function buildPrompt(array $context, string $question): string
    {
        $money = fn (float $value): string => $this->context->rupiah($value);

        $label = $context['label'];
        $current = $context['current'];

        $lines = [];
        $lines[] = '== DATA BISNIS ==';
        $lines[] = "Periode: {$label}";
        $lines[] = 'Omzet bersih periode ini (tanpa pajak): '.$money($current['net_revenue']);
        $lines[] = 'Total tagihan pelanggan: '.$money($current['billed_revenue']);
        $lines[] = 'Pajak terkumpul: '.$money($current['tax_collected']);
        $lines[] = 'Harga pokok penjualan (HPP): '.$money($current['hpp']);
        $lines[] = 'Laba kotor: '.$money($current['gross_profit']);
        $lines[] = 'Pengeluaran operasional: '.$money($current['operating_expenses']);
        $lines[] = 'Laba bersih: '.$money($current['net_profit']);
        $lines[] = 'Jumlah transaksi lunas: '.$current['transaction_count'];

        if (! empty($context['daily'])) {
            $lines[] = '';
            $lines[] = '== TREN HARIAN ==';
            foreach ($context['daily'] as $date => $total) {
                $lines[] = "{$date}: ".$money((float) $total);
            }
        }

        $previous = $context['previous'];
        $lines[] = '';
        $lines[] = '== PERBANDINGAN PERIODE SEBELUMNYA ==';
        $lines[] = 'Periode pembanding: '.$context['previous_label'];
        $lines[] = 'Omzet bersih: '.$money($previous['net_revenue']);
        $lines[] = 'Laba bersih: '.$money($previous['net_profit']);
        $lines[] = 'Jumlah transaksi: '.$previous['transaction_count'];

        $revenueChange = $this->context->changePercent($current['net_revenue'], $previous['net_revenue']);
        $profitChange = $this->context->changePercent($current['net_profit'], $previous['net_profit']);
        $lines[] = 'Perubahan omzet: '.$this->describeChange($revenueChange);
        $lines[] = 'Perubahan laba bersih: '.$this->describeChange($profitChange);

        $lines[] = '';
        $lines[] = '== PRODUK TERLARIS PERIODE INI ==';
        $lines[] = $context['top_products']->isEmpty()
            ? '- Belum ada transaksi lunas pada periode ini.'
            : $context['top_products']->map(function (array $row) use ($money): string {
                $qty = rtrim(rtrim(number_format($row['qty'], 2, ',', '.'), '0'), ',');

                return "- {$row['name']}: {$qty} item, ".$money($row['sales']);
            })->implode("\n");

        $lines[] = '';
        $lines[] = '== STOK KRITIS ==';
        $lines[] = $context['low_stock']->isEmpty()
            ? '- Tidak ada produk dengan stok menipis.'
            : $context['low_stock']->map(function (array $row): string {
                $stock = rtrim(rtrim(number_format($row['stock'], 2, ',', '.'), '0'), ',');

                return "- {$row['name']}: sisa {$stock}";
            })->implode("\n");

        $lines[] = '';
        $lines[] = '== PERTANYAAN PENGGUNA ==';
        $lines[] = $question;

        return implode("\n", $lines);
    }

    private function describeChange(?float $percent): string
    {
        if ($percent === null) {
            return 'tidak dapat dihitung (periode sebelumnya nol)';
        }

        return sprintf('%+.1f%% (%s)', $percent, $this->context->direction($percent));
    }

    private function prependHistory(string $prompt, array $history): string
    {
        $lines = ['== PERCAKAPAN SEBELUMNYA =='];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? '') === 'user' ? 'Pengguna' : 'Coach';
            $lines[] = "{$role}: ".mb_substr((string) ($turn['content'] ?? ''), 0, 500);
        }
        $lines[] = '';
        $lines[] = '(Lanjutkan percakapan di atas secara konsisten.)';
        $lines[] = '';

        return implode("\n", $lines).$prompt;
    }

    /**
     * Sesi milik user yang sedang login. ID dari request tidak dipercaya
     * penuh, tapi tetap diverifikasi kepemilikannya.
     */
    private function resolveSession(mixed $sessionId, ?string $period = null): ?AiSession
    {
        $userId = (int) auth()->id();
        if (! $userId) {
            return null;
        }

        if ($sessionId) {
            $session = AiSession::where('user_id', $userId)->whereKey($sessionId)->first();
            if ($session) {
                if ($period && $session->period !== $period) {
                    $session->update(['period' => $period]);
                }

                return $session;
            }
        }

        return AiSession::create([
            'user_id' => $userId,
            'period' => $period ?? 'today',
            'history' => [],
        ]);
    }
}
