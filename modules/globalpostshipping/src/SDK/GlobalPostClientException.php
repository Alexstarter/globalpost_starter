<?php

namespace GlobalPostShipping\SDK;

use RuntimeException;
use Throwable;

class GlobalPostClientException extends RuntimeException
{
    private ?int $statusCode;

    private ?string $errorCode;

    private ?string $responseBody;

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $responseHeaders;

    /**
     * @param array<string, array<int, string>>|null $responseHeaders
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        ?string $errorCode = null,
        ?string $responseBody = null,
        ?array $responseHeaders = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
        $this->responseHeaders = $responseHeaders;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    public function getResponseHeaders(): ?array
    {
        return $this->responseHeaders;
    }
}
