<?php

namespace App\Console\Commands;

use App\Models\AiSession;
use Illuminate\Console\Command;

/**
 * Membersihkan sesi AI Business Coach yang sudah tidak berguna.
 *
 * Sesi dibuat sebelum panggilan AI, jadi panggilan yang gagal atau browser
 * yang storage-nya dihapus meninggalkan baris yatim. Tanpa pruning, tabel ini
 * tumbuh tanpa batas.
 */
class PruneAiSessions extends Command
{
    protected $signature = 'ai:prune-sessions {--days=30 : Simpan riwayat selama N hari} {--keep-empty= : Hapus juga sesi kosong}';

    protected $description = 'Hapus sesi AI Business Coach yang sudah lama tidak dipakai';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $query = AiSession::where('updated_at', '<', $cutoff);

        if ($this->option('keep-empty') === null) {
            // Sesi kosong sama sekali tidak pernah dipakai -> buang lebih cepat.
            $query->orWhere(function ($builder): void {
                $builder->where('updated_at', '<', $cutoff)
                    ->where(function ($inner): void {
                        $inner->whereNull('history')->orWhere('history', '=', '[]');
                    });
            });
        }

        $deleted = 0;
        $query->chunkById(200, function ($sessions) use (&$deleted): void {
            $deleted += $sessions->each->delete()->count();
        });

        $this->info("Sesi AI dihapus: {$deleted} (retensi {$days} hari).");

        return self::SUCCESS;
    }
}
