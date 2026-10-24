<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;
interface KeySource { public function get(string $binding, string $direction): string; }
