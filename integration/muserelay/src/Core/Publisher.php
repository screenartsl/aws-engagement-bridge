<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Support\Facades\Http;
use MuseRelay\AwsEngagementBridge\BridgeSignature;

final class Publisher
{
    public function __construct(private string $url, private KeySource $keys) {}
    public function publish(string $binding, array $command): void
    {
        $url = parse_url($this->url);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || ($url['path'] ?? '') !== '/outbound' || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || isset($url['port']) || !preg_match('/^[a-z0-9-]+\.execute-api\.eu-west-1\.amazonaws\.com$/D', $url['host'] ?? '')) throw new BridgeException('invalid_outbound_url', 503);
        $body = json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = BridgeSignature::sign($this->keys->get($binding, 'outbound'), 'POST', '/outbound', $body);
        $result = Http::withHeaders(['x-bridge-binding' => $binding, 'x-bridge-timestamp' => $headers['timestamp'], 'x-bridge-nonce' => $headers['nonce'], 'x-bridge-signature' => $headers['signature']])
            ->withBody($body, 'application/json')->withoutRedirecting()->connectTimeout(3)->timeout(10)->post($this->url);
        if ($result->status() !== 202 || $result->json('id') !== $command['commandId'] || $result->json('status') !== 'accepted') throw new BridgeException('publish_not_acknowledged', 503);
    }
}
