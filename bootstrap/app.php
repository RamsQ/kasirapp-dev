<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        
        // 1. [FIXED] Mengecualikan route Face ID & QR Menu dari verifikasi CSRF
        // Menambahkan 'menu/order' agar pelanggan guest bisa melakukan POST pesanan
        $middleware->validateCsrfTokens(except: [
            '/face-auth/login',
            '/face-auth/fetch-user',
            'menu/order', 
        ]);

        // 2. [FIXED] Append Middleware default untuk grup 'web'
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // 3. [NEW] Alias Middleware Spatie untuk proteksi halaman/route
        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'permission'         => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        
        // Memastikan session stateful agar auth user terbaca sempurna
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        /**
         * Custom Error Handling
         * Mengalihkan response error ke halaman Error.jsx milik Inertia
         */

        // --- Handle Error Permission Spatie (403) ---
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            return Inertia::render('Error', ['status' => 403])
                ->toResponse($request)
                ->setStatusCode(403);
        });

        // --- Handle Error Akses Ditolak Umum (403) ---
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return Inertia::render('Error', ['status' => 403])
                ->toResponse($request)
                ->setStatusCode(403);
        });

        // --- Handle Halaman Tidak Ditemukan (404) ---
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return Inertia::render('Error', ['status' => 404])
                ->toResponse($request)
                ->setStatusCode(404);
        });

    })->create();