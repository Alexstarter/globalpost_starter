<?php

namespace GlobalPostShipping\SDK;

interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     *
     * @throws HttpTransportException
     */
    public function request(string $method, string $url, array $headers = [], array $options = []): HttpResponse;
}
