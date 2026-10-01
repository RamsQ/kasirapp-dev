<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Kesalahan umum untuk semua penyedia AI.
 *
 * Pesannya sudah diterjemahkan ke Bahasa Indonesia dan aman ditampilkan ke
 * pengguna: tidak pernah memuat API key, URL internal, atau isi exception.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 502,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
