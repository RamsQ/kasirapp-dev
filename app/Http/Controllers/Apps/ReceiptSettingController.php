<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\ReceiptSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReceiptSettingController extends Controller
{
    public function index()
    {
        // Ambil data (selalu ambil baris pertama)
        $setting = ReceiptSetting::first();

        return Inertia::render('Dashboard/Settings/Receipt', [
            'setting' => $setting
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'store_name'    => 'required|string|max:255',
            'store_address' => 'nullable|string',
            'store_phone'   => 'nullable|string|max:20',
            'store_footer'  => 'nullable|string',
            'store_logo'    => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $setting = ReceiptSetting::firstOrNew(['id' => 1]);

        // 1. Upload Logo jika ada
        if ($request->hasFile('store_logo')) {
            // Hapus logo lama
            if ($setting->store_logo) {
                Storage::disk('public')->delete('receipt/' . $setting->store_logo);
            }
            // Simpan logo baru
            $image = $request->file('store_logo');
            $image->storeAs('public/receipt', $image->hashName());
            $setting->store_logo = $image->hashName();
        }

        // 2. Simpan Text
        $setting->store_name    = $request->store_name;
        $setting->store_address = $request->store_address;
        $setting->store_phone   = $request->store_phone;
        $setting->store_footer  = $request->store_footer;
        $setting->save();

        return back()->with('success', 'Pengaturan Struk berhasil disimpan!');
    }
}