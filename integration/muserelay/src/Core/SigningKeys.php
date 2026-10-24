<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class SigningKeys implements KeySource
{
    public function __construct(private string $path) {}
    public function get(string $binding, string $direction): string
    {
        $path = realpath($this->path);
        if (!$path || !is_file($path) || !is_readable($path) || (!str_starts_with($path, '/run/secrets/') && !str_starts_with($path, '/etc/muserelay/aws-bridge/'))
            || (fileperms($path) & 0077) !== 0 || filesize($path) > 32000) throw new BridgeException('protected_signing_keys_required', 503);
        $keys = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $key = $keys[$binding][$direction] ?? null;
        if (!is_string($key) || strlen($key) < 32 || ($keys[$binding]['core'] ?? null) === ($keys[$binding]['outbound'] ?? null)) throw new BridgeException('signing_key_missing', 503);
        return $key;
    }
}
