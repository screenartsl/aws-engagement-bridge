<?php
return [
    'enabled' => (bool) env('AWS_BRIDGE_ENABLED', false),
    'organization_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('AWS_BRIDGE_ORGANIZATION_IDS', ''))))),
    'signing_keys_file' => env('AWS_BRIDGE_SIGNING_KEYS_FILE', '/run/secrets/muserelay-aws-bridge.json'),
    'outbound_url' => env('AWS_BRIDGE_OUTBOUND_URL', ''),
    // Keys themselves are never placed in this config or config cache.
];
