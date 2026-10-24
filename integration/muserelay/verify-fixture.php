<?php
declare(strict_types=1);

// Local interoperability test only: reads synthetic JSON from stdin; no network,
// application bootstrap, database connections, credentials or file writes.
require __DIR__ . '/BridgeSignature.php';

use MuseRelay\AwsEngagementBridge\BridgeSignature;

$fixture = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
try {
    BridgeSignature::verify($fixture['secret'], $fixture['method'], $fixture['path'], $fixture['body'], $fixture['headers'], $fixture['now']);
    echo json_encode(BridgeSignature::sign($fixture['secret'], $fixture['method'], $fixture['path'], $fixture['body'], $fixture['now'], $fixture['headers']['nonce']), JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
    exit(1);
}
