<?php

namespace App\Services\Esi\Diagnostics;

use App\Services\Esi\Diagnostics\Streams\EsiDiagnosticsStream;

/**
 * Buffers events on the stream. A failed write is counted as collection loss
 * when the buffer can still say so; it never throws into the ESI path.
 */
class StreamSink implements EsiDiagnosticsSink
{
    /** Losses that could not even be counted in the buffer (it was unreachable). */
    public static int $uncountedLoss = 0;

    public function __construct(private EsiDiagnosticsStream $stream) {}

    public function push(array $event): void
    {
        try {
            $this->stream->add(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            try {
                $this->stream->recordDrop();
            } catch (\Throwable $inner) {
                self::$uncountedLoss++;
            }
        }
    }
}
