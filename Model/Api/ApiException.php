<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Api;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $body = '',
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Network errors, timeouts, rate limits and server errors are worth another try later;
     * any other 4xx means the request itself is wrong and will fail the same way again.
     */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
