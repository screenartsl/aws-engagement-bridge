<?php
return [
    'id'=>'aws-engagement-bridge',
    'name'=>'AWS Engagement Bridge',
    'version'=>'0.5.14',
    'core_api'=>1,
    'provider'=>\MuseRelay\AwsEngagementBridge\Core\CoreIntegrationProvider::class,
    'health'=>\MuseRelay\AwsEngagementBridge\Core\CoreReadiness::class,
    'autoload'=>['MuseRelay\\AwsEngagementBridge\\Core\\'=>'src/Core'],
    'classmap'=>['MuseRelay\\AwsEngagementBridge\\BridgeSignature'=>'BridgeSignature.php'],
];
