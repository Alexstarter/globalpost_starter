<?php

namespace GlobalPostShipping\Logger;

use GlobalPostShipping\SDK\LoggerInterface;
use PrestaShopLogger;
use Tools;

class PrestaShopLoggerAdapter implements LoggerInterface
{
    private const LEVEL_SEVERITY = [
        'emergency' => 4,
        'alert' => 4,
        'critical' => 4,
        'error' => 3,
        'warning' => 2,
        'notice' => 1,
        'info' => 1,
        'debug' => 1,
    ];

    private string $channel;

    public function __construct(string $channel = 'globalpostshipping')
    {
        $this->channel = $channel;
    }

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
        $normalizedLevel = Tools::strtolower($level);
        $severity = self::LEVEL_SEVERITY[$normalizedLevel] ?? 1;
        $contextPayload = $this->encodeContext($context);
        $logMessage = $message;

        if ($contextPayload !== null) {
            $logMessage .= ' | context: ' . $contextPayload;
        }

        PrestaShopLogger::addLog($logMessage, $severity, null, $this->channel, null, true);
    }

    private function encodeContext(array $context): ?string
    {
        if (empty($context)) {
            return null;
        }

        $sanitized = $this->sanitizeContext($context);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    private function sanitizeContext(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$key] = $this->sanitizeScalar($key, (string) ($value ?? ''));
                continue;
            }

            $result[$key] = '[filtered]';
        }

        return $result;
    }

    private function sanitizeScalar(string $key, string $value): string
    {
        $normalizedKey = Tools::strtolower($key);
        if ($normalizedKey === 'authorization' || strpos($normalizedKey, 'token') !== false) {
            return '[filtered]';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (Tools::strlen($trimmed) > 160) {
            return Tools::substr($trimmed, 0, 157) . '...';
        }

        return $trimmed;
    }
}
