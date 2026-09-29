<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

final class FirmwareDownloadSlice
{
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
        public readonly int $total,
        public readonly bool $partial
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request, int $total, int $maxChunk): self
    {
        $total = max(0, $total);
        $maxChunk = max(32, $maxChunk);
        $query = $request->getQueryParams();
        $offset = self::intParam($query['offset'] ?? null);
        $length = self::intParam($query['length'] ?? null);

        if ($offset === null && $length === null) {
            $range = self::parseRangeHeader($request->getHeaderLine('Range'), $total);
            if ($range !== null) {
                $offset = $range[0];
                $length = $range[1];
            }
        }

        if ($offset === null && $length === null) {
            return new self(0, $total, $total, false);
        }

        $offset = max(0, $offset ?? 0);
        if ($offset >= $total) {
            return new self($offset, 0, $total, true);
        }

        $remaining = $total - $offset;
        $want = $length ?? $remaining;
        if ($want < 0) {
            $want = 0;
        }
        $want = min($want, $remaining, $maxChunk);

        return new self($offset, $want, $total, true);
    }

    public function unsatisfiable(): bool
    {
        return $this->partial && ($this->offset >= $this->total || $this->length <= 0);
    }

    public function lastByte(): int
    {
        if ($this->length <= 0) {
            return max(0, $this->offset - 1);
        }

        return $this->offset + $this->length - 1;
    }

    public function contentRange(): string
    {
        if ($this->unsatisfiable()) {
            return 'bytes */' . $this->total;
        }

        return 'bytes ' . $this->offset . '-' . $this->lastByte() . '/' . $this->total;
    }

    private static function intParam(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function parseRangeHeader(string $header, int $total): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^bytes=\s*(\d+)\s*-\s*(\d+)?\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : max(0, $total - 1);
        if ($end < $start) {
            return null;
        }

        return [$start, $end - $start + 1];
    }
}
