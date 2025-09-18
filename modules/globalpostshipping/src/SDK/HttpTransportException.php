<?php

namespace GlobalPostShipping\SDK;

use RuntimeException;
use Throwable;

class HttpTransportException extends RuntimeException
{
    private ?HttpResponse $response;

    public function __construct(string $message, int $code = 0, ?HttpResponse $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?HttpResponse
    {
        return $this->response;
    }
}
