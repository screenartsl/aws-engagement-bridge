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
        $result = $this->post($binding, '/outbound', $command, 10);
        if ($result->status() !== 202 || $result->json('id') !== $command['commandId'] || $result->json('status') !== 'accepted') throw new BridgeException('publish_not_acknowledged', 503);
    }

    /** Approved WhatsApp templates of the binding's WABA, read from AWS (signed, read-only). */
    public function templates(string $binding): array
    {
        $result = $this->post($binding, '/templates', ['version' => 1, 'bindingId' => $binding], 20);
        $templates = $result->status() === 200 ? $result->json('templates') : null;
        if (!is_array($templates)) throw new BridgeException('templates_unavailable', 503);
        return $templates;
    }

    /**
     * Transactional email/SMS (POST /notify). Synchronous: returns AWS's result. A definitive refusal (disabled, not allowed,
     * daily cap, replay) comes back as `rejected`; transport failures throw so the caller can retry with the same notifyId.
     * @return array{state: string, providerMessageId?: string, errorCode?: string, duplicate?: bool}
     */
    public function notify(string $binding, array $command): array
    {
        $result = $this->post($binding, '/notify', $command, 15);
        if ($result->status() === 200 && $result->json('id') === $command['notifyId'] && in_array($result->json('state'), ['accepted', 'rejected', 'uncertain'], true)) {
            return array_filter(['state' => $result->json('state'), 'providerMessageId' => $result->json('providerMessageId'),
                'errorCode' => $result->json('errorCode'), 'duplicate' => $result->json('duplicate') === true ?: null], fn ($v) => $v !== null);
        }
        if (in_array($result->status(), [409, 422, 429], true) && is_string($result->json('error'))) return ['state' => 'rejected', 'errorCode' => $result->json('error')];
        throw new BridgeException('notify_unavailable', 503);
    }

    private function post(string $binding, string $path, array $data, int $timeout): \Illuminate\Http\Client\Response
    {
        $url = parse_url($this->url);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || ($url['path'] ?? '') !== '/outbound' || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || isset($url['port']) || !preg_match('/^[a-z0-9-]+\.execute-api\.eu-west-1\.amazonaws\.com$/D', $url['host'] ?? '')) throw new BridgeException('invalid_outbound_url', 503);
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = BridgeSignature::sign($this->keys->get($binding, 'outbound'), 'POST', $path, $body);
        return Http::withHeaders(['x-bridge-binding' => $binding, 'x-bridge-timestamp' => $headers['timestamp'], 'x-bridge-nonce' => $headers['nonce'], 'x-bridge-signature' => $headers['signature']])
            ->withBody($body, 'application/json')->withoutRedirecting()->connectTimeout(3)->timeout($timeout)->post('https://'.$url['host'].$path);
    }
}
