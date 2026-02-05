<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // [PROTEKSI BIOMETRIK SMART]
        // Ambil data langsung dari DB untuk memastikan keakuratan is_face_mandatory DAN face_data
        $userCheck = DB::table('users')
            ->where('email', $this->email)
            ->whereNull('deleted_at')
            ->select('is_face_mandatory', 'face_data')
            ->first();

        /**
         * LOGIKA PERBAIKAN:
         * Password ditolak HANYA JIKA:
         * 1. Kolom is_face_mandatory bernilai TRUE (1)
         * 2. DAN Kolom face_data TIDAK KOSONG (User sudah mendaftarkan wajah)
         * * Jika face_data kosong, user dibiarkan login pakai password agar bisa daftar wajah dulu.
         */
        if ($userCheck) {
            $isMandatory = (bool) $userCheck->is_face_mandatory;
            $hasFaceData = !empty($userCheck->face_data);

            if ($isMandatory && $hasFaceData) {
                RateLimiter::hit($this->throttleKey());
                
                throw ValidationException::withMessages([
                    'email' => 'KEAMANAN AKTIF: Akun ini diwajibkan menggunakan Face ID karena data wajah sudah terdaftar. Login password dinonaktifkan.',
                ]);
            }
        }

        // Jika lolos pengecekan di atas, lanjut verifikasi password
        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}