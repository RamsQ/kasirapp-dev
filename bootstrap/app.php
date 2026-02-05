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
        // [BARU] Mengecualikan route Face ID dari verifikasi CSRF (Solusi Error 419)
        $middleware->validateCsrfTokens(except: [
            '/face-auth/login',
            '/face-auth/fetch-user',
        ]);

        // Mendaftarkan middleware HandleInertiaRequests ke dalam grup 'web'
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Menambahkan alias untuk Spatie Permission
        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'permission'         => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        
        // Memastikan session stateful agar auth user terbaca di middleware
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        // --- 1. Handle Error Permission Spatie (403) ---
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            return Inertia::render('Error', ['status' => 403])
                ->toResponse($request)
                ->setStatusCode(403);
        });

        // --- 2. Handle Error Akses Ditolak Umum (403) ---
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return Inertia::render('Error', ['status' => 403])
                ->toResponse($request)
                ->setStatusCode(403);
        });

        // --- 3. Handle Halaman Tidak Ditemukan (404) ---
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return Inertia::render('Error', ['status' => 404])
                ->toResponse($request)
                ->setStatusCode(404);
        });

    })->create();