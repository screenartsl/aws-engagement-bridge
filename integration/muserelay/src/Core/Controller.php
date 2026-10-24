<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MuseRelay\AwsEngagementBridge\BridgeSignature;

final class Controller
{
    public function __construct(private Store $store, private Outbox $outbox, private KeySource $keys) {}
    public function events(Request $request): JsonResponse { return $this->receive($request, false); }
    public function receipts(Request $request): JsonResponse { return $this->receive($request, true); }
    private function receive(Request $request, bool $receipt): JsonResponse
    {
        try {
            $raw = $request->getContent();
            if (strlen($raw) > 20000) throw new BridgeException('payload_too_large', 413);
            $id = (string) $request->header('x-bridge-binding', '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id)) throw new BridgeException('unauthorized', 401);
            $this->store->binding($id);
            $path = '/api/aws-engagement-bridge/'.($receipt ? 'receipts' : 'events');
            if ($request->getPathInfo() !== $path || !$request->isMethod('POST')) throw new BridgeException('unauthorized', 401);
            $headers = ['timestamp' => (string) $request->header('x-bridge-timestamp', ''), 'nonce' => (string) $request->header('x-bridge-nonce', ''), 'signature' => (string) $request->header('x-bridge-signature', '')];
            BridgeSignature::verify($this->keys->get($id, 'core'), 'POST', $path, $raw, $headers, $this->store->now());
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new BridgeException('invalid_payload');
            $this->store->reserveNonce($id, $headers['nonce'], $path);
            $ack = $receipt ? $this->outbox->receipt($id, $data) : $this->store->accept($id, $data);
            return new JsonResponse($ack, $ack['status'] === 'duplicate' ? 200 : 202, ['Cache-Control' => 'no-store']);
        } catch (BridgeException $e) { return new JsonResponse(['error' => $e->reason], $e->httpStatus); }
        catch (\InvalidArgumentException) { return new JsonResponse(['error' => 'unauthorized'], 401); }
        catch (\JsonException) { return new JsonResponse(['error' => 'invalid_json'], 400); }
        catch (\Throwable) { return new JsonResponse(['error' => 'bridge_unavailable'], 503); }
    }
}
