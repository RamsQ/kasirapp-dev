<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * Menampilkan halaman pengaturan
     */
    public function index()
    {
        $canManageSettings = auth()->user()->can('settings.cgos')
            || auth()->user()->can('settings.update');

        return Inertia::render('Dashboard/Settings/Index', [
            // Mengirimkan data pertama, atau objek kosong agar frontend tidak error
            'settings' => Setting::first() ?? (object) [
                'cogs_method' => 'AVERAGE',
                'tax_enabled' => false,
                'tax_rate' => null,
                'loyalty_enabled' => true,
                'loyalty_points_per_100' => 1,
                'kitchen_display_enabled' => false,
                'kitchen_display_payment_status' => 'both',
                'kitchen_display_enabled_at' => null,
            ],

            // Mengirimkan status hak akses untuk kontrol tampilan di React (Frontend)
            'canManageCgos' => $canManageSettings,
        ]);
    }

    /**
     * Memperbarui pengaturan aplikasi (Hanya untuk yang memiliki izin settings.cgos)
     */
    public function update(Request $request)
    {
        // 1. KEAMANAN: Cek izin pengaturan sistem
        if (! auth()->user()->can('settings.cgos') && ! auth()->user()->can('settings.update')) {
            return back()->with('error', 'Anda tidak memiliki izin untuk mengubah pengaturan sistem.');
        }

        // 2. VALIDASI DATA
        $validated = $request->validate([
            'cogs_method' => 'required|in:AVERAGE,FIFO,LIFO,SPECIFIC',
            'tax_enabled' => ['required', 'boolean'],
            'tax_rate' => [
                Rule::requiredIf($request->boolean('tax_enabled')),
                'nullable',
                'numeric',
                'min:0.01',
                'max:100',
                'decimal:0,2',
            ],
            'loyalty_enabled' => ['nullable', 'boolean'],
            'loyalty_points_per_100' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'kitchen_display_enabled' => ['required', 'boolean'],
            'kitchen_display_payment_status' => ['required', Rule::in(['unpaid', 'paid', 'both'])],
        ]);

        $currentSettings = Setting::first();
        $validated['loyalty_enabled'] ??= $currentSettings?->loyalty_enabled ?? true;
        $validated['loyalty_points_per_100'] ??= $currentSettings?->loyalty_points_per_100 ?? 1;

        $this->syncKitchenDisplayState($currentSettings, $validated);

        // 3. EKSEKUSI UPDATE
        // Menggunakan updateOrCreate: Mencari ID 1, jika tidak ada maka buat baru
        Setting::updateOrCreate(
            ['id' => 1],
            $validated
        );

        return back()->with('success', 'Pengaturan sistem, pajak, dan Kitchen Display berhasil diperbarui!');
    }

    /**
     * Selaraskan state Kitchen Display saat sakelarnya berubah.
     *
     * Dua hal yang harus terjadi, keduanya hanya saat transisi MATI -> NYALA:
     *
     *  1. `kitchen_display_enabled_at` dicatat. KDS memakai ini sebagai batas
     *     bawah: order yang dibuat sebelum waktu ini tidak pernah masuk dapur,
     *     jadi tidak boleh muncul di layar. Tanpa batas ini, menyalakan KDS
     *     langsung menampilkan seluruh riwayat order.
     *
     *  2. Order yang masih menggantung ('pending'/'preparing'/'ready') ditutup
     *     menjadi 'completed'. Kalau tidak, order yang happens masuk dari jalur
     *     yang lupa menulis status, atau order dari waktu KDS mati, akan tetap
     *     menggantung dan memenuhi layar Limited 100 baris pertama.
     *
     * Transisi NYALA -> MATI sengaja tidak mengubah apa pun. Order yang sudah
     * ada di dapur tetap menggantung supaya tidak hilang kalau KDS dinyalakan
     * lagi satu menit kemudian.
     *
     * @param  Setting|null  $current
     * @param  array<string, mixed>  $validated
     */
    private function syncKitchenDisplayState(?Setting $current, array &$validated): void
    {
        $wasEnabled = (bool) ($current?->kitchen_display_enabled);
        $nowEnabled = (bool) ($validated['kitchen_display_enabled'] ?? false);

        // Belum pernah menyala: biarkan apa adanya, jangan sentuh order.
        if ($wasEnabled && $nowEnabled) {
            return;
        }

        if (! $nowEnabled) {
            return;
        }

        $validated['kitchen_display_enabled_at'] = now();

        DB::transaction(function (): void {
            Transaction::query()
                ->whereIn('kitchen_status', ['pending', 'preparing', 'ready'])
                ->update(['kitchen_status' => 'completed']);

            Hold::query()
                ->whereIn('kitchen_status', ['pending', 'preparing', 'ready'])
                ->update(['kitchen_status' => 'completed']);
        });
    }
}
