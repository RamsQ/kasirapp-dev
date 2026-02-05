<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * Menampilkan halaman pengaturan
     */
    public function index()
    {
        return Inertia::render('Dashboard/Settings/Index', [
            // Mengirimkan data pertama, atau objek kosong agar frontend tidak error
            'settings' => Setting::first() ?? (object) ['cogs_method' => 'AVERAGE']
        ]);
    }

    /**
     * Memperbarui pengaturan aplikasi
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'cogs_method' => 'required|in:AVERAGE,FIFO,LIFO,SPECIFIC',
        ]);

        // Menggunakan updateOrCreate: Mencari ID 1, jika tidak ada maka buat baru
        Setting::updateOrCreate(
            ['id' => 1], 
            $validated
        );

        return back()->with('success', 'Pengaturan metode COGS berhasil diperbarui!');
    }
}