<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class ToolGuard
{
    /** Pure lookups: no business side effects, so no idempotency checkpoint (avoids holding a transaction). */
    private const READ_ONLY = ['knowledge_search'];

    public function __construct(private Store $store, private Scope $scope, private CorePort $core) {}
    public function execute(string $name, array $context, callable $dispatch, array $args = []): array
    {
        $this->store->requireOperation('agent');
        $event = $this->scope->event;
        $binding = $this->scope->binding;
        if (!$event || !$binding || (int) ($context['channel_id'] ?? 0) !== (int) $binding->channel_id
            || (int) ($context['workspace_id'] ?? 0) !== (int) $binding->workspace_id
            || (int) ($context['organization_id'] ?? 0) !== (int) $binding->organization_id
            || (int) ($context['conversation_id'] ?? 0) !== (int) $event->conversation_id) {
            return ['ok' => false, 'error' => 'bridge_turn_context_required', 'message' => 'The action could not be authorized.'];
        }
        $this->store->binding($binding->id);
        $context['message_id'] = (int) $event->message_id;
        if (in_array($name, self::READ_ONLY, true)) {
            $this->core->authorizeTurn($binding, $event);
            return $dispatch($context);
        }
        // Any other native tool may act (tickets, calendar, leads…). A retried turn replaying the SAME call
        // (tool + arguments) gets the stored result instead of acting twice; different arguments run normally.
        $key = $name.'#'.substr(hash('sha256', json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''), 0, 24);
        return $this->store->action($event, $key, function () use ($binding, $event, $context, $dispatch): array {
            $this->core->authorizeTurn($binding, $event);
            return $dispatch($context);
        });
    }
}
