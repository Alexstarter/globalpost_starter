<?php

namespace GlobalPostShipping\SDK;

use Throwable;

class GlobalPostClient
{
    public const MODE_TEST = 'TEST';
    public const MODE_PROD = 'PROD';

    private const BASE_URLS = [
        self::MODE_TEST => 'https://test-api.globalpost.com.ua',
        self::MODE_PROD => 'https://api.globalpost.com.ua',
    ];

    private string $token;

    private string $mode;

    private string $baseUrl;

    private HttpTransportInterface $transport;

    private LoggerInterface $logger;

    private bool $debug;

    private int $maxRetries;

    private float $retryDelay;

    public function __construct(
        string $token,
        string $mode = self::MODE_TEST,
        array $config = [],
        ?HttpTransportInterface $transport = null,
        ?LoggerInterface $logger = null
    ) {
        $normalizedMode = strtoupper($mode);
        if (!isset(self::BASE_URLS[$normalizedMode])) {
            $normalizedMode = self::MODE_TEST;
        }

        $this->token = trim($token);
        $this->mode = $normalizedMode;
        $this->baseUrl = self::BASE_URLS[$this->mode];
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 10.0;
        $connectTimeout = isset($config['connect_timeout']) ? (float) $config['connect_timeout'] : 5.0;
        $this->transport = $transport ?? new CurlHttpTransport($timeout, $connectTimeout);
        $this->logger = $logger ?? new NullLogger();
        $this->debug = (bool) ($config['debug'] ?? false);
        $this->maxRetries = max(0, (int) ($config['max_retries'] ?? 1));
        $this->retryDelay = max(0.0, (float) ($config['retry_delay'] ?? 0.5));
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getCountries(): array
    {
        $response = $this->performRequest('GET', '/public/tariff-international/countries');

        return $this->decodeJson($response);
    }

    /**
     * @param array<string, scalar|array<scalar>> $params
     *
     * @return array<int|string, mixed>
     */
    public function getOptions(array $params): array
    {
        $response = $this->performRequest('GET', '/public/tariff-international/get-options', [
            'query' => $params,
        ]);

        return $this->decodeJson($response);
    }

    /**
     * @param array<string, scalar|array<scalar>> $formData
     *
     * @return array<int|string, mixed>
     */
    public function createShortOrder(array $formData): array
    {
        $response = $this->performRequest('POST', '/api/create-short-order', [
            'form_params' => $formData,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        return $this->decodeJson($response);
    }

    public function printLabel(string $locale, string $orderId): string
    {
        $response = $this->performRequest('GET', sprintf('/api/orders/print-new/%s/%s', rawurlencode($locale), rawurlencode($orderId)), [
            'headers' => [
                'Accept' => 'application/pdf',
            ],
        ]);

        return $response->getBody();
    }

    public function printInvoice(string $orderId): string
    {
        $response = $this->performRequest('GET', sprintf('/api/orders/print-invoice/%s', rawurlencode($orderId)), [
            'headers' => [
                'Accept' => 'application/pdf',
            ],
        ]);

        return $response->getBody();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function performRequest(string $method, string $path, array $options = []): HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => $options['headers']['Accept'] ?? 'application/json',
        ];

        if (isset($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[$name] = $value;
            }
        }

        $query = $options['query'] ?? null;
        if ($query !== null) {
            $queryString = $this->buildQuery($query);
            if ($queryString !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
            }
        }

        $body = null;
        if (isset($options['form_params'])) {
            $body = $this->buildQuery($options['form_params']);
        } elseif (isset($options['body'])) {
            $body = (string) $options['body'];
        }

        if ($body !== null && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $attempt = 0;
        $maxAttempts = $this->maxRetries + 1;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->logDebug('GlobalPost request', [
                'method' => strtoupper($method),
                'url' => $url,
                'attempt' => $attempt,
                'query_keys' => $query !== null ? array_keys((array) $query) : [],
                'has_body' => $body !== null,
            ]);

            try {
                $response = $this->transport->request($method, $url, $headers, [
                    'body' => $body,
                ]);
            } catch (HttpTransportException $exception) {
                if ($this->shouldRetryTransport($exception) && $attempt < $maxAttempts) {
                    $this->logWarning('Retrying GlobalPost request after transport error.', [
                        'attempt' => $attempt,
                        'error_code' => $exception->getCode(),
                    ]);
                    $this->sleep();
                    $lastException = $exception;
                    continue;
                }

                throw new GlobalPostClientException(
                    'Failed to call GlobalPost API: ' . $exception->getMessage(),
                    null,
                    null,
                    null,
                    null,
                    $exception
                );
            }

            $status = $response->getStatusCode();
            $this->logDebug('GlobalPost response', [
                'status' => $status,
                'attempt' => $attempt,
            ]);

            if ($this->shouldRetryStatus($status) && $attempt < $maxAttempts) {
                $this->logWarning('Retrying GlobalPost request after HTTP error.', [
                    'status' => $status,
                    'attempt' => $attempt,
                ]);
                $this->sleep();
                $lastException = null;
                continue;
            }

            if ($status >= 400) {
                throw $this->createExceptionFromResponse($response);
            }

            return $response;
        }

        if ($lastException instanceof Throwable) {
            throw new GlobalPostClientException(
                'GlobalPost API request failed after retries: ' . $lastException->getMessage(),
                null,
                null,
                null,
                null,
                $lastException
            );
        }

        throw new GlobalPostClientException('GlobalPost API request failed after retries.');
    }

    private function shouldRetryTransport(HttpTransportException $exception): bool
    {
        return in_array($exception->getCode(), [
            CURLE_OPERATION_TIMEDOUT,
            CURLE_COULDNT_CONNECT,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_RESOLVE_PROXY,
        ], true);
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status >= 500 && $status < 600;
    }

    private function sleep(): void
    {
        if ($this->retryDelay > 0) {
            usleep((int) ($this->retryDelay * 1_000_000));
        }
    }

    private function createExceptionFromResponse(HttpResponse $response): GlobalPostClientException
    {
        $body = $response->getBody();
        $headers = $response->getHeaders();
        $status = $response->getStatusCode();
        $message = 'GlobalPost API request failed with status ' . $status . '.';
        $errorCode = null;

        $contentType = $response->getHeaderLine('Content-Type');
        if (is_string($contentType) && strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    $message = $decoded['message'];
                } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                    $message = $decoded['error'];
                }

                if (isset($decoded['code']) && is_scalar($decoded['code'])) {
                    $errorCode = (string) $decoded['code'];
                }
            }
        }

        return new GlobalPostClientException($message, $status, $errorCode, $body, $headers);
    }

    /**
     * @param array<string, scalar|array<scalar>> $params
     */
    private function buildQuery(array $params): string
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->sanitizeArray($value);
            } elseif (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, scalar|null|array<scalar|null>>
     */
    private function sanitizeArray(array $value): array
    {
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizeArray($item);
            } elseif (is_scalar($item) || $item === null) {
                $sanitized[$key] = $item;
            }
        }

        return $sanitized;
    }

    private function decodeJson(HttpResponse $response): array
    {
        $body = $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new GlobalPostClientException(
                'Failed to decode GlobalPost API response.',
                $response->getStatusCode(),
                null,
                $body,
                $response->getHeaders()
            );
        }

        return $decoded;
    }

    private function logDebug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->logger->debug($message, $this->maskContext($context));
        }
    }

    private function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->maskContext($context));
    }

    private function maskContext(array $context): array
    {
        $masked = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $masked[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = array_map(static function ($item) {
                    if (is_scalar($item) || $item === null) {
                        return $item;
                    }

                    return '[filtered]';
                }, $value);
                continue;
            }

            $masked[$key] = '[filtered]';
        }

        return $masked;
    }
}
