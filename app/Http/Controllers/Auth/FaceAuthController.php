<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class FaceAuthController extends Controller {
    /**
     * Simpan descriptor wajah saat registrasi (Hanya Owner/Super Admin yang bisa akses lewat UI)
     */
    public function registerFace(Request $request) {
        $user = auth()->user();
        
        // Simpan array descriptor ke kolom face_data
        $user->update(['face_data' => $request->descriptor]);

        return response()->json([
            'status' => 'success',
            'message' => 'Data wajah berhasil diperbarui.'
        ]);
    }

    /**
     * Ambil data user & status kebijakan Face ID berdasarkan email
     */
    public function fetchUser(Request $request) {
        $user = User::where('email', $request->email)->first();

        if ($user) {
            return response()->json([
                'status'        => 'success',
                'user_id'       => $user->id,
                'face_data'     => $user->face_data, // Berisi array descriptor atau null
                'is_mandatory'  => (bool) $user->is_face_mandatory, // Status wajib dari Owner
            ]);
        }

        return response()->json([
            'status' => 'error', 
            'message' => 'Akun dengan email tersebut tidak ditemukan.'
        ]);
    }

    /**
     * Proses Login otomatis setelah verifikasi wajah di sisi Client (Frontend) berhasil
     */
    public function login(Request $request) {
        $user = User::findOrFail($request->user_id);

        // Lakukan login manual
        Auth::login($user);

        // WAJIB: Regenerasi session untuk keamanan & menghindari Session Fixation
        $request->session()->regenerate();

        // Redirect dinamis berdasarkan Role
        // Jika Admin ke Dashboard Utama, jika Kasir langsung ke halaman Transaksi
        $url = $user->hasRole('super-admin') ? '/dashboard' : '/dashboard/transactions';

        return response()->json([
            'status'   => 'success', 
            'redirect' => $url
        ]);
    }

    /**
     * Fitur tambahan: Owner bisa menghapus data wajah user via Admin (Opsional)
     */
    public function deleteFaceData(Request $request) {
        if (!auth()->user()->hasRole('super-admin')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $user = User::findOrFail($request->user_id);
        $user->update([
            'face_data' => null,
            'is_face_mandatory' => false // Otomatis matikan mandatory jika data wajah dihapus
        ]);

        return response()->json(['status' => 'success', 'message' => 'Data wajah berhasil dihapus.']);
    }
}