<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\ReportSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportSettingController extends Controller
{
    /**
     * Menampilkan halaman pengaturan laporan
     */
    public function index()
    {
        $setting = ReportSetting::first();

        return Inertia::render('Dashboard/Reports/Managed', [
            'setting' => $setting
        ]);
    }

    /**
     * Menyimpan atau memperbarui pengaturan laporan
     */
    public function store(Request $request)
    {
        // 1. Validasi Input
        $request->validate([
            'is_active'  => 'required|boolean',
            'is_weekly'  => 'required|boolean',
            'is_monthly' => 'required|boolean',
            'method'     => 'required|in:whatsapp,email',
            'target'     => 'required',
            'send_at'    => 'required',
            'wa_api_key' => 'nullable|string',
        ]);

        // 2. Update atau Buat Data di ID 1
        ReportSetting::updateOrCreate(
            ['id' => 1],
            [
                'is_active'  => $request->is_active,
                'is_weekly'  => $request->is_weekly,
                'is_monthly' => $request->is_monthly,
                'method'     => $request->method,
                'target'     => $request->target,
                'send_at'    => $request->send_at,
                'wa_api_key' => $request->wa_api_key,
            ]
        );

        return back()->with('success', 'Pengaturan siklus laporan berhasil diperbarui!');
    }
}