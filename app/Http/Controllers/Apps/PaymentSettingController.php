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
    public function edit()
    {
        $setting = PaymentSetting::firstOrCreate([], [
            'default_gateway' => 'cash',
        ]);

        return Inertia::render('Dashboard/Settings/Payment', [
            'setting' => $setting,
            'supportedGateways' => [
                ['value' => 'cash', 'label' => 'Tunai'],
                ['value' => PaymentSetting::GATEWAY_MIDTRANS, 'label' => 'Midtrans'],
                ['value' => PaymentSetting::GATEWAY_XENDIT, 'label' => 'Xendit'],
                ['value' => PaymentSetting::GATEWAY_QRIS, 'label' => 'QRIS Manual'], // Tambahkan ini
            ],
        ]);
    }

    public function update(Request $request)
    {
        $setting = PaymentSetting::firstOrCreate([], [
            'default_gateway' => 'cash',
        ]);

        $data = $request->validate([
            'default_gateway' => [
                'required',
                Rule::in(['cash', PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT, PaymentSetting::GATEWAY_QRIS]),
            ],
            'midtrans_enabled' => ['boolean'],
            'midtrans_server_key' => ['nullable', 'string'],
            'midtrans_client_key' => ['nullable', 'string'],
            'midtrans_production' => ['boolean'],
            'xendit_enabled' => ['boolean'],
            'xendit_secret_key' => ['nullable', 'string'],
            'xendit_public_key' => ['nullable', 'string'],
            'xendit_production' => ['boolean'],
            // --- VALIDASI QRIS BARU ---
            'qris_manual_enabled' => ['boolean'],
            'qris_manual_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $midtransEnabled = (bool) ($request->midtrans_enabled ?? false);
        $xenditEnabled = (bool) ($request->xendit_enabled ?? false);
        $qrisEnabled = (bool) ($request->qris_manual_enabled ?? false);

        // Validasi Midtrans
        if ($midtransEnabled && (empty($data['midtrans_server_key']) || empty($data['midtrans_client_key']))) {
            return back()->withErrors(['midtrans_server_key' => 'Server & Client key Midtrans wajib diisi.'])->withInput();
        }

        // Validasi Xendit
        if ($xenditEnabled && empty($data['xendit_secret_key'])) {
            return back()->withErrors(['xendit_secret_key' => 'Secret key Xendit wajib diisi.'])->withInput();
        }

        // Validasi QRIS (Gambar wajib ada jika baru pertama kali diaktifkan)
        if ($qrisEnabled && !$setting->qris_manual_image && !$request->hasFile('qris_manual_image')) {
            return back()->withErrors(['qris_manual_image' => 'Gambar QRIS wajib diunggah saat mengaktifkan QRIS Manual.'])->withInput();
        }

        // Validasi Gateway Default harus aktif
        if (
            $data['default_gateway'] !== 'cash'
            && !(($data['default_gateway'] === PaymentSetting::GATEWAY_MIDTRANS && $midtransEnabled)
                || ($data['default_gateway'] === PaymentSetting::GATEWAY_XENDIT && $xenditEnabled)
                || ($data['default_gateway'] === PaymentSetting::GATEWAY_QRIS && $qrisEnabled))
        ) {
            return back()->withErrors(['default_gateway' => 'Gateway default harus dalam kondisi aktif.'])->withInput();
        }

        // --- LOGIKA UPLOAD GAMBAR QRIS ---
        if ($request->hasFile('qris_manual_image')) {
            // Hapus foto lama jika ada
            if ($setting->qris_manual_image) {
                Storage::disk('public')->delete('payments/' . $setting->qris_manual_image);
            }

            // Simpan foto baru
            $image = $request->file('qris_manual_image');
            $filename = time() . '.' . $image->getClientOriginalExtension();
            $image->storeAs('payments', $filename, 'public');
            $setting->qris_manual_image = $filename;
        }

        // Update data lainnya
        $setting->update([
            'default_gateway' => $data['default_gateway'],
            'midtrans_enabled' => $midtransEnabled,
            'midtrans_server_key' => $data['midtrans_server_key'],
            'midtrans_client_key' => $data['midtrans_client_key'],
            'midtrans_production' => (bool) ($data['midtrans_production'] ?? false),
            'xendit_enabled' => $xenditEnabled,
            'xendit_secret_key' => $data['xendit_secret_key'],
            'xendit_public_key' => $data['xendit_public_key'],
            'xendit_production' => (bool) ($data['xendit_production'] ?? false),
            'qris_manual_enabled' => $qrisEnabled,
            'qris_manual_image' => $setting->qris_manual_image,
        ]);

        return redirect()
            ->route('settings.payments.edit')
            ->with('success', 'Konfigurasi pembayaran berhasil diperbarui.');
    }
}