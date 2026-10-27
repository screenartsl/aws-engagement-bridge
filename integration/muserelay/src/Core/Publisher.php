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

    /** Upload slot for one outbound file (POST /media): a key under this binding and a presigned PUT valid for minutes. */
    public function uploadSlot(string $binding, string $type, string $mime, int $size): array
    {
        $result = $this->post($binding, '/media', ['version' => 1, 'bindingId' => $binding, 'type' => $type, 'mimeType' => $mime, 'size' => $size], 10);
        $key = $result->json('key'); $url = $result->json('url');
        if ($result->status() === 422 && is_string($result->json('error'))) throw new BridgeException((string) $result->json('error'), 422);
        if ($result->status() !== 200 || !is_string($key) || !preg_match('~^outbound/'.preg_quote($binding, '~').'/[a-f0-9]{32}/file$~D', $key) || !is_string($url)) throw new BridgeException('media_slot_unavailable', 503);
        // The slot may only point at that key in an S3 bucket in the Bridge region.
        $u = parse_url($url);
        if (($u['scheme'] ?? '') !== 'https' || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\.s3\.eu-west-1\.amazonaws\.com$/D', $u['host'] ?? '')
            || ($u['path'] ?? '') !== '/'.$key || isset($u['user']) || isset($u['port'])) throw new BridgeException('media_slot_unavailable', 503);
        return ['key' => $key, 'url' => $url];
    }

    public function upload(array $slot, string $body, string $mime): void
    {
        $result = Http::withBody($body, $mime)->withoutRedirecting()->connectTimeout(5)->timeout(60)->put($slot['url']);
        if (!$result->successful()) throw new BridgeException('media_upload_failed', 503);
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
