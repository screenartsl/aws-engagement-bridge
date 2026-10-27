<?php
return [
    'enabled' => (bool) env('AWS_BRIDGE_ENABLED', false),
    // Independent gates: installing/enabling the extension never enables an operation.
    'receive_enabled' => env('AWS_BRIDGE_RECEIVE_ENABLED', false) === true,
    'agent_enabled' => env('AWS_BRIDGE_AGENT_ENABLED', false) === true,
    'send_enabled' => env('AWS_BRIDGE_SEND_ENABLED', false) === true,
    // Transactional email (SES) / SMS (End User Messaging SMS) through POST /notify. Off by default.
    // Queue for the immediate drain (DrainJob); the scheduled drain every 10 s stays as the safety net.
    'queue' => env('AWS_BRIDGE_QUEUE', 'chat-high'),
    'notify_enabled' => env('AWS_BRIDGE_NOTIFY_ENABLED', false) === true,
    'organization_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('AWS_BRIDGE_ORGANIZATION_IDS', ''))))),
    'signing_keys_file' => env('AWS_BRIDGE_SIGNING_KEYS_FILE', '/run/secrets/muserelay-aws-bridge.json'),
    'outbound_url' => env('AWS_BRIDGE_OUTBOUND_URL', ''),
    // Keys themselves are never placed in this config or config cache.
];
