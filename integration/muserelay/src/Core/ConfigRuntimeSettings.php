<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

final class ConfigRuntimeSettings implements RuntimeSettings
{
    public function __construct(private \Illuminate\Contracts\Config\Repository $config) {}
    public function enabled(): bool { return (bool)$this->config->get('aws_bridge.enabled',false); }
    public function organizations(): array { return array_values(array_filter(array_map('intval',(array)$this->config->get('aws_bridge.organization_ids',[])),fn($id)=>$id>0)); }
}
