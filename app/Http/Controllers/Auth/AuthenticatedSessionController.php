<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Menampilkan halaman login.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Menangani permintaan autentikasi masuk.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // 1. CEK KEBIJAKAN BIOMETRIK (Pintu Pertama)
        // Menggunakan query langsung untuk memastikan performa maksimal dan data terbaru
        $userAttempt = User::where('email', $request->email)
            ->select('id', 'email', 'is_face_mandatory', 'face_data')
            ->first();

        /**
         * LOGIKA PENCEGAHAN TERKUNCI (SMART LOCK):
         * Login password ditolak hanya jika:
         * - Pimpinan mewajibkan Face ID (is_face_mandatory = true)
         * - DAN User sudah punya data wajah (face_data tidak kosong)
         * Jika wajah belum didaftarkan, user masih boleh masuk via password untuk setup awal.
         */
        if ($userAttempt && $userAttempt->is_face_mandatory && !empty($userAttempt->face_data)) {
            throw ValidationException::withMessages([
                'email' => 'Pintu Masuk Terkunci: Anda sudah mendaftarkan Face ID. Silakan gunakan tombol Verifikasi Wajah untuk login.',
            ]);
        }

        // 2. PROSES AUTENTIKASI STANDAR
        // Jika lolos pengecekan biometrik, lanjutkan pengecekan password
        $request->authenticate();

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = $request->user();

        // --- LOGIKA PENGALIHAN BERDASARKAN ROLE ---

        // 1. Jika user adalah pimpinan (Owner, Admin, atau Super-Admin), langsung ke Dashboard
        if ($user->hasAnyRole(['admin', 'owner', 'super-admin'])) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        // 2. Jika user memiliki role 'cashier', langsung arahkan ke halaman POS/Transaksi
        if ($user->hasRole('cashier')) {
            return redirect()->route('transactions.index');
        }

        // 3. Default Fallback
        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Menghancurkan sesi autentikasi (Logout).
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}