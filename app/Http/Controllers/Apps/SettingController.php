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
            'settings' => Setting::first() ?? (object) ['cogs_method' => 'AVERAGE'],
            
            // Mengirimkan status hak akses untuk kontrol tampilan di React (Frontend)
            'canManageCgos' => auth()->user()->can('settings.cgos'),
        ]);
    }

    /**
     * Memperbarui pengaturan aplikasi (Hanya untuk yang memiliki izin settings.cgos)
     */
    public function update(Request $request)
    {
        // 1. KEAMANAN: Cek apakah user memiliki izin untuk mengubah System Core (CGOS)
        if (!auth()->user()->can('settings.cgos')) {
            return back()->with('error', 'Anda tidak memiliki izin untuk mengubah pengaturan inti sistem (CGOS).');
        }

        // 2. VALIDASI DATA
        $validated = $request->validate([
            'cogs_method' => 'required|in:AVERAGE,FIFO,LIFO,SPECIFIC',
        ]);

        // 3. EKSEKUSI UPDATE
        // Menggunakan updateOrCreate: Mencari ID 1, jika tidak ada maka buat baru
        Setting::updateOrCreate(
            ['id' => 1], 
            $validated
        );

        return back()->with('success', 'Pengaturan metode COGS berhasil diperbarui!');
    }
}