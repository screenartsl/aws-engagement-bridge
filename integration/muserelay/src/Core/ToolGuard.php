<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class ToolGuard
{
    public function __construct(private Store $store, private Scope $scope, private CorePort $core) {}
    public function execute(string $name, array $context, callable $dispatch): array
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
        if (!in_array($name, ['knowledge_search', 'support_get_my_tickets', 'support_create_ticket'], true)) return ['ok' => false, 'error' => 'bridge_tool_not_allowed', 'message' => 'This demo only supports knowledge lookup and support requests.'];
        $context['message_id'] = (int) $event->message_id;
        if ($name === 'support_create_ticket') return $this->store->action($event, $name, function () use ($binding, $event, $context, $dispatch): array {
            $this->core->authorizeTurn($binding, $event);
            return $dispatch($context);
        });
        $this->core->authorizeTurn($binding, $event);
        return $dispatch($context);
    }
}
