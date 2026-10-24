<?php
declare(strict_types=1);

namespace MuseRelay\AwsEngagementBridge;

use InvalidArgumentException;

/** Framework-independent protocol component. Does not load Laravel or register routes. */
final class BridgeSignature
{
    /** @return array{timestamp:string,nonce:string,signature:string} */
    public static function sign(string $secret, string $method, string $path, string $rawBody, ?int $now = null, ?string $nonce = null): array
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('weak_secret');
        }
        $timestamp = (string) ($now ?? time());
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = implode("\n", [$timestamp, $nonce, strtoupper($method), $path, $rawBody]);
        return ['timestamp' => $timestamp, 'nonce' => $nonce, 'signature' => hash_hmac('sha256', $canonical, $secret)];
    }

    /**
     * Pure verification. The eventual controller MUST also atomically reserve the
     * nonce for 600 seconds and persist the business event id before returning 202.
     * @param array{timestamp:string,nonce:string,signature:string} $headers
     */
    public static function verify(string $secret, string $method, string $path, string $rawBody, array $headers, ?int $now = null): void
    {
        $timestamp = $headers['timestamp'] ?? '';
        $nonce = $headers['nonce'] ?? '';
        $signature = $headers['signature'] ?? '';
        if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(($now ?? time()) - (int) $timestamp) > 300) {
            throw new InvalidArgumentException('expired_signature');
        }
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $nonce) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            throw new InvalidArgumentException('invalid_signature');
        }
        $expected = self::sign($secret, $method, $path, $rawBody, (int) $timestamp, $nonce);
        if (!hash_equals($expected['signature'], $signature)) {
            throw new InvalidArgumentException('invalid_signature');
        }
    }
}
