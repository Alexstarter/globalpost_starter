<?php

namespace GlobalPostShipping\SDK;

class HttpResponse
{
    private int $statusCode;

    /**
     * @var array<string, array<int, string>>
     */
    private array $headers;

    private string $body;

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaderLine(string $header): ?string
    {
        $normalized = strtolower($header);
        if (!isset($this->headers[$normalized])) {
            return null;
        }

        return implode(', ', $this->headers[$normalized]);
    }
}
