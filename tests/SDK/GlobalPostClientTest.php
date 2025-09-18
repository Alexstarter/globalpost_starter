<?php

require_once __DIR__ . '/../bootstrap.php';

use GlobalPostShipping\SDK\GlobalPostClient;
use GlobalPostShipping\SDK\GlobalPostClientException;
use GlobalPostShipping\SDK\HttpResponse;
use GlobalPostShipping\SDK\HttpTransportException;
use GlobalPostShipping\SDK\HttpTransportInterface;
use GlobalPostShipping\SDK\LoggerInterface;

/**
 * @param mixed $expected
 * @param mixed $actual
 * @param string $message
 */
function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Failed asserting that values are identical.');
    }
}

/**
 * @param callable $test
 */
function runTest(string $name, callable $test): void
{
    try {
        $test();
        echo '.';
    } catch (Throwable $exception) {
        echo PHP_EOL . 'Test failed: ' . $name . PHP_EOL;
        echo $exception->getMessage() . PHP_EOL;
        exit(1);
    }
}

class MockTransport implements HttpTransportInterface
{
    /**
     * @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public array $requests = [];

    /**
     * @var array<int, HttpResponse|HttpTransportException>
     */
    private array $queue;

    public function __construct(HttpResponse ...$responses)
    {
        $this->queue = $responses;
    }

    public function push(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function pushException(HttpTransportException $exception): void
    {
        $this->queue[] = $exception;
    }

    public function request(string $method, string $url, array $headers = [], array $options = []): HttpResponse
    {
        $body = null;
        if (array_key_exists('body', $options)) {
            $body = $options['body'];
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[$name] = $value;
        }

        $this->requests[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $normalizedHeaders,
            'body' => $body,
        ];

        if (count($this->queue) === 0) {
            throw new RuntimeException('Mock transport queue exhausted.');
        }

        $response = array_shift($this->queue);
        if ($response instanceof HttpTransportException) {
            throw $response;
        }

        return $response;
    }
}

class MemoryLogger implements LoggerInterface
{
    public array $messages = [];

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->messages[] = [$level, $message, $context];
    }
}

runTest('it builds countries request', function (): void {
    $transport = new MockTransport(new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['UA', 'US'], JSON_THROW_ON_ERROR)));
    $logger = new MemoryLogger();

    $client = new GlobalPostClient('token', GlobalPostClient::MODE_TEST, ['debug' => true], $transport, $logger);
    $countries = $client->getCountries();

    assertSame(['UA', 'US'], $countries, 'Countries response should be decoded.');

    $request = $transport->requests[0];
    assertSame('GET', $request['method'], 'Method should be GET.');
    assertSame('https://test-api.globalpost.com.ua/public/tariff-international/countries', $request['url'], 'URL should match base URL.');
    assertSame('Bearer token', $request['headers']['Authorization'] ?? null, 'Authorization header missing.');
    assertSame('application/json', $request['headers']['Accept'] ?? null, 'Default Accept header should be JSON.');
    assertSame(null, $request['body'], 'GET request should not send body.');
    assertSame('debug', $logger->messages[0][0] ?? null, 'Debug log should be recorded when debug enabled.');
});

runTest('it builds tariff options query string', function (): void {
    $responseBody = json_encode(['options' => [['id' => 1]]], JSON_THROW_ON_ERROR);
    $transport = new MockTransport(new HttpResponse(200, ['content-type' => ['application/json']], $responseBody));

    $client = new GlobalPostClient('abc', GlobalPostClient::MODE_TEST, [], $transport, new MemoryLogger());
    $result = $client->getOptions([
        'from_country' => 'UA',
        'to_country' => 'US',
        'weight' => 1200,
    ]);

    assertSame(['options' => [['id' => 1]]], $result, 'Options response should be decoded.');

    $request = $transport->requests[0];
    assertSame('https://test-api.globalpost.com.ua/public/tariff-international/get-options?from_country=UA&to_country=US&weight=1200', $request['url'], 'Query string should be appended.');
});

runTest('it encodes form data when creating short order', function (): void {
    $transport = new MockTransport(new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['order' => '123'], JSON_THROW_ON_ERROR)));

    $client = new GlobalPostClient('t', GlobalPostClient::MODE_PROD, [], $transport, new MemoryLogger());
    $result = $client->createShortOrder([
        'recipient_name' => 'John',
        'recipient_phone' => '+380001112233',
    ]);

    assertSame(['order' => '123'], $result, 'Order response should be decoded.');

    $request = $transport->requests[0];
    assertSame('https://api.globalpost.com.ua/api/create-short-order', $request['url'], 'Production base URL should be used.');
    assertSame('recipient_name=John&recipient_phone=%2B380001112233', $request['body'], 'Form data should be URL encoded.');
    assertSame('application/x-www-form-urlencoded', $request['headers']['Content-Type'] ?? null, 'Content-Type should be set.');
});

runTest('it retries on server error', function (): void {
    $transport = new MockTransport(
        new HttpResponse(500, ['content-type' => ['application/json']], json_encode(['message' => 'Server error'], JSON_THROW_ON_ERROR)),
        new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['ok' => true], JSON_THROW_ON_ERROR))
    );

    $client = new GlobalPostClient('token', GlobalPostClient::MODE_TEST, ['max_retries' => 2, 'retry_delay' => 0.0], $transport, new MemoryLogger());
    $result = $client->getCountries();

    assertSame(['ok' => true], $result, 'Client should retry and succeed.');
    assertSame(2, count($transport->requests), 'Client should perform two attempts.');
});

runTest('it throws informative exception on client error', function (): void {
    $body = json_encode(['message' => 'Invalid data', 'code' => 'invalid_data'], JSON_THROW_ON_ERROR);
    $transport = new MockTransport(new HttpResponse(400, ['content-type' => ['application/json']], $body));

    $client = new GlobalPostClient('token', GlobalPostClient::MODE_TEST, [], $transport, new MemoryLogger());

    try {
        $client->createShortOrder(['foo' => 'bar']);
    } catch (GlobalPostClientException $exception) {
        assertSame(400, $exception->getStatusCode(), 'Status code should be exposed.');
        assertSame('invalid_data', $exception->getErrorCode(), 'Error code should be exposed.');
        assertSame('Invalid data', $exception->getMessage(), 'Message should be derived from response.');
        return;
    }

    throw new RuntimeException('Expected GlobalPostClientException was not thrown.');
});

runTest('it returns binary bodies for documents', function (): void {
    $transport = new MockTransport(new HttpResponse(200, ['content-type' => ['application/pdf']], '%PDF-1.4 label'));

    $client = new GlobalPostClient('token', GlobalPostClient::MODE_TEST, [], $transport, new MemoryLogger());
    $pdf = $client->printLabel('uk', 'ORDER-1');

    assertSame('%PDF-1.4 label', $pdf, 'Binary response should be returned as-is.');
    $request = $transport->requests[0];
    assertSame('application/pdf', $request['headers']['Accept'] ?? null, 'Accept header should request PDF.');
});

echo PHP_EOL . 'All tests passed' . PHP_EOL;
