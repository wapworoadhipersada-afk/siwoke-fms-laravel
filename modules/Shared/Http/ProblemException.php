<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An API error rendered as RFC 9457 `application/problem+json`, carrying a
 * stable machine-readable `code` that mobile and vendor clients can branch on.
 */
final class ProblemException extends HttpException
{
    private function __construct(int $status, private readonly string $problemCode, private readonly string $title, string $detail)
    {
        parent::__construct($status, $detail);
    }

    public static function unauthenticated(string $code, string $detail): self
    {
        return new self(401, $code, 'Unauthenticated', $detail);
    }

    public static function forbidden(string $code, string $detail): self
    {
        return new self(403, $code, 'Forbidden', $detail);
    }

    public static function notFound(string $code, string $detail): self
    {
        return new self(404, $code, 'Not Found', $detail);
    }

    public static function conflict(string $code, string $detail): self
    {
        return new self(409, $code, 'Conflict', $detail);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => $this->title,
            'status' => $this->getStatusCode(),
            'detail' => $this->getMessage(),
            'code' => $this->problemCode,
        ], $this->getStatusCode(), ['Content-Type' => 'application/problem+json']);
    }
}
