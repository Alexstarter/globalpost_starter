<?php

namespace GlobalPostShipping\SDK;

use RuntimeException;

class CurlHttpTransport implements HttpTransportInterface
{
    private float $timeout;

    private float $connectTimeout;

    public function __construct(float $timeout = 10.0, float $connectTimeout = 5.0)
    {
        if ($timeout <= 0.0) {
            throw new RuntimeException('Timeout must be greater than zero.');
        }

        if ($connectTimeout <= 0.0) {
            throw new RuntimeException('Connect timeout must be greater than zero.');
        }

        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $headers = [], array $options = []): HttpResponse
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new HttpTransportException('Unable to initialise cURL session.');
        }

        $encodedHeaders = [];
        foreach ($headers as $name => $value) {
            $encodedHeaders[] = $name . ': ' . $value;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => $encodedHeaders,
            CURLOPT_HEADER => true,
        ];

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['verify']) && $options['verify'] === false) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if (!curl_setopt_array($curl, $curlOptions)) {
            curl_close($curl);
            throw new HttpTransportException('Failed to configure cURL session.');
        }

        $result = curl_exec($curl);
        if ($result === false) {
            $errorMessage = curl_error($curl);
            $errorCode = curl_errno($curl);
            curl_close($curl);
            throw new HttpTransportException($errorMessage !== '' ? $errorMessage : 'Unknown cURL error.', $errorCode);
        }

        $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) ?: 0;
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE) ?: 0;
        curl_close($curl);

        $rawHeaders = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);

        $headersArray = $this->parseHeaders($rawHeaders);

        return new HttpResponse($statusCode, $headersArray, $body);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split('/\r?\n/', $rawHeaders) ?: [];
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $normalized = strtolower(trim($name));
            if (!isset($headers[$normalized])) {
                $headers[$normalized] = [];
            }

            $headers[$normalized][] = trim($value);
        }

        return $headers;
    }
}
