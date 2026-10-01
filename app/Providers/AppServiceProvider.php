<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Gunakan template reset password LakuPOS tanpa mengganti class notification Laravel.
        // Dengan begitu integrasi password broker dan test notifikasi tetap kompatibel.
        ResetPassword::toMailUsing(function ($notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->subject('🔐 Keamanan Akun - Reset Password LakuPOS')
                ->view('emails.reset_password', [
                    'name' => $notifiable->name,
                    'url' => $url,
                ]);
        });

        // --- LOGIC SUPER ADMIN (KARTU SAKTI) ---
        // Gate::before dijalankan SEBELUM pengecekan permission Spatie.
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        RateLimiter::for('ai-chat', function (Request $request): Limit {
            $maxAttempts = max(1, (int) config('services.ai.requests_per_minute', 10));
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute($maxAttempts)
                ->by('ai-chat:'.$identity)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'reply' => 'Terlalu banyak permintaan AI. Coba lagi sebentar lagi.',
                ], Response::HTTP_TOO_MANY_REQUESTS, $headers));
        });
    }
}
