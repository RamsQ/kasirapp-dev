<?php

namespace App\Support;

use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Dispatch helper for notifications that must never break a write.
 *
 * OrderPlaced and OrderDeleted are ShouldBroadcast events. Laravel queues them
 * through BroadcastEvent, which has no try/catch of its own, and this project
 * runs QUEUE_CONNECTION=sync -- so the broadcast is performed inline, inside the
 * request and inside the surrounding DB transaction.
 *
 * The consequence was severe: whenever Pusher was slow or unreachable, every
 * public QR order blocked for ~10 seconds, the broadcast threw, the
 * transaction rolled back, and the customer received a 500 for an order the
 * kitchen never saw. A missed realtime ping is a cosmetic problem; a lost
 * order is not.
 *
 * Use this instead of event() for anything that only notifies.
 */
final class SafeEvent
{
    /**
     * Dispatch an event, swallowing (but reporting) any failure.
     *
     * @param  string|null  $context  Short label used in the log entry.
     */
    public static function dispatch(object $event, ?string $context = null): bool
    {
        try {
            Event::dispatch($event);

            return true;
        } catch (Throwable $e) {
            report($e);

            if ($context) {
                logger()->warning('Notifikasi realtime gagal, operasi tetap disimpan.', [
                    'context' => $context,
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }
}
