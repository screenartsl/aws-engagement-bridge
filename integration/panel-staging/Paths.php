<?php
declare(strict_types=1);
final class BridgePanelPaths
{
    public static string $root;
    public static array $config;
    public static string $data;
    public static function load(): array
    {
        $test=getenv('APP_ENV')==='testing';
        $root=$test?(string)getenv('BRIDGE_PANEL_ROOT'):'/srv/muserelay-aws-stage';
        if(realpath($root)!==$root || ($test?!str_starts_with($root,'/tmp/muserelay-bridge-panel-'):$root!=='/srv/muserelay-aws-stage'))throw new RuntimeException('Isolated panel root required');
        if($test) {
            if(!is_file($root.'/.bridge-panel-local') || trim(file_get_contents($root.'/host-netns'))===readlink('/proc/self/ns/net'))throw new RuntimeException('Offline network namespace required');
            $file=$root.'/panel-local.json';
        } else {
            if(getenv('APP_ENV')!=='bridge-staging' || !is_file($root.'/.isolated-bridge-stage'))throw new RuntimeException('Staging environment required');
            $file='/etc/muserelay/aws-bridge/panel-stage.json';
        }
        if(realpath($file)!==$file || !is_file($file) || (fileperms($file)&0077)!==0 || filesize($file)>20000)throw new RuntimeException('Protected panel configuration required');
        $c=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        foreach(['appKey','messagesKey','indexKey'] as $key)if(!str_starts_with($c[$key]??'','base64:') || strlen(base64_decode(substr($c[$key],7),true)?:'')!==32)throw new RuntimeException('Fresh panel encryption material required');
        if(($c['organizationId']??null)!==97101 || ($c['workspaceId']??null)!==10 || ($c['channelId']??null)!==20 || ($c['userId']??null)!==97101 || ($c['hostname']??'')!=='aws-bridge-staging.muserelay.com')throw new RuntimeException('Fixed isolated demo scope required');
        // No inference or output is installed by this receive-first panel profile.
        if(($c['agentEnabled']??false)!==false || ($c['sendEnabled']??false)!==false)throw new RuntimeException('Receive-only profile required');
        self::$root=$root;self::$data=$test?$root.'/data':'/var/lib/muserelay-aws-stage';
        $c['database']=$test?'bridge_panel_test':'bridge_phase4_test';$c['databaseUser']=$test?'bridge_panel':'muserelay-aws-stage';
        $c['socket']=$test?$root.'/pgsock':'/run/muserelay-aws-stage/pgsock';$c['port']=$test?55435:55434;
        if(($c['expiresAt']??0)<=time() || $c['expiresAt']>time()+96*3600)throw new RuntimeException('Short-lived staging configuration required');
        return self::$config=$c;
    }
}
