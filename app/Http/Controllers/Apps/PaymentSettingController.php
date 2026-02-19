<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PaymentSettingController extends Controller
{
    /**
     * TAMPILAN PENGATURAN PEMBAYARAN
     */
    public function edit()
    {
        // Mengambil data pertama atau membuat baru jika belum ada
        $setting = PaymentSetting::firstOrCreate([], [
            'default_gateway' => 'cash',
        ]);

        return Inertia::render('Dashboard/Settings/Payment', [
            'setting' => $setting,
            'supportedGateways' => [
                ['value' => 'cash', 'label' => 'Tunai (Cash)'],
                ['value' => PaymentSetting::GATEWAY_MIDTRANS, 'label' => 'Midtrans (Otomatis)'],
                ['value' => PaymentSetting::GATEWAY_XENDIT, 'label' => 'Xendit (Otomatis)'],
                ['value' => PaymentSetting::GATEWAY_QRIS, 'label' => 'QRIS Manual (Upload Gambar)'],
            ],
        ]);
    }

    /**
     * PROSES UPDATE KONFIGURASI
     */
    public function update(Request $request)
    {
        $setting = PaymentSetting::firstOrCreate([], [
            'default_gateway' => 'cash',
        ]);

        // 1. Validasi Input Dasar
        $data = $request->validate([
            'default_gateway' => [
                'required',
                Rule::in(['cash', PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT, PaymentSetting::GATEWAY_QRIS]),
            ],
            'midtrans_enabled'    => ['nullable', 'boolean'],
            'midtrans_server_key' => ['nullable', 'string', 'max:255'],
            'midtrans_client_key' => ['nullable', 'string', 'max:255'],
            'midtrans_production' => ['nullable', 'boolean'],
            
            'xendit_enabled'    => ['nullable', 'boolean'],
            'xendit_secret_key' => ['nullable', 'string', 'max:255'],
            'xendit_public_key' => ['nullable', 'string', 'max:255'],
            'xendit_production' => ['nullable', 'boolean'],
            
            'qris_manual_enabled' => ['nullable', 'boolean'],
            'qris_manual_image'   => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $midtransEnabled = (bool) ($request->midtrans_enabled ?? false);
        $xenditEnabled   = (bool) ($request->xendit_enabled ?? false);
        $qrisEnabled     = (bool) ($request->qris_manual_enabled ?? false);

        // 2. Validasi Khusus Midtrans (Wajib Isi Key jika diaktifkan)
        if ($midtransEnabled) {
            if (empty($data['midtrans_server_key'])) {
                return back()->withErrors(['midtrans_server_key' => 'Server Key Midtrans wajib diisi jika modul diaktifkan.'])->withInput();
            }
            if (empty($data['midtrans_client_key'])) {
                return back()->withErrors(['midtrans_client_key' => 'Client Key Midtrans wajib diisi jika modul diaktifkan.'])->withInput();
            }
        }

        // 3. Validasi Khusus Xendit
        if ($xenditEnabled && empty($data['xendit_secret_key'])) {
            return back()->withErrors(['xendit_secret_key' => 'Secret Key Xendit wajib diisi jika modul diaktifkan.'])->withInput();
        }

        // 4. Validasi Khusus QRIS Manual (Wajib upload gambar jika belum ada di database)
        if ($qrisEnabled && !$setting->qris_manual_image && !$request->hasFile('qris_manual_image')) {
            return back()->withErrors(['qris_manual_image' => 'Gambar QRIS wajib diunggah jika mode QRIS Manual diaktifkan.'])->withInput();
        }

        // 5. Proteksi Integritas: Gateway default tidak boleh dalam kondisi modul disabled
        if ($data['default_gateway'] === PaymentSetting::GATEWAY_MIDTRANS && !$midtransEnabled) {
            return back()->withErrors(['default_gateway' => 'Gagal menjadikan Midtrans sebagai default karena modul sedang non-aktif.']);
        }
        if ($data['default_gateway'] === PaymentSetting::GATEWAY_XENDIT && !$xenditEnabled) {
            return back()->withErrors(['default_gateway' => 'Gagal menjadikan Xendit sebagai default karena modul sedang non-aktif.']);
        }
        if ($data['default_gateway'] === PaymentSetting::GATEWAY_QRIS && !$qrisEnabled) {
            return back()->withErrors(['default_gateway' => 'Gagal menjadikan QRIS Manual sebagai default karena modul sedang non-aktif.']);
        }

        // 6. Logika Simpan Gambar QRIS Manual
        $filename = $setting->qris_manual_image;
        if ($request->hasFile('qris_manual_image')) {
            // Hapus gambar lama jika ada
            if ($setting->qris_manual_image) {
                Storage::disk('public')->delete('payments/' . $setting->qris_manual_image);
            }
            
            $image = $request->file('qris_manual_image');
            $filename = 'qris_' . time() . '_' . rand(100, 999) . '.' . $image->getClientOriginalExtension();
            $image->storeAs('payments', $filename, 'public');
        }

        // 7. Simpan Seluruh Perubahan ke Database
        $setting->update([
            'default_gateway'     => $data['default_gateway'],
            
            'midtrans_enabled'    => $midtransEnabled,
            'midtrans_server_key' => $data['midtrans_server_key'],
            'midtrans_client_key' => $data['midtrans_client_key'],
            'midtrans_production' => (bool) ($data['midtrans_production'] ?? false),
            
            'xendit_enabled'    => $xenditEnabled,
            'xendit_secret_key' => $data['xendit_secret_key'],
            'xendit_public_key' => $data['xendit_public_key'],
            'xendit_production' => (bool) ($data['xendit_production'] ?? false),
            
            'qris_manual_enabled' => $qrisEnabled,
            'qris_manual_image'   => $filename,
        ]);

        return redirect()
            ->route('settings.payments.edit')
            ->with('success', 'Konfigurasi metode pembayaran berhasil diperbarui.');
    }
}