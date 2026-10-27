<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Console\Command;

/** Controlled demo send: one confirmation for one operation. Prints only the outcome, never the recipient or the text. */
final class NotifyCommand extends Command
{
    protected $signature = 'aws-bridge:notify {channel : AWS WhatsApp channel id} {contact : Contact id} {kind : email|sms}
        {reference : type:id, e.g. appointment:42} {text : Message body} {--subject= : Email subject}';
    protected $description = 'Send one email (SES) or SMS (End User Messaging SMS) confirmation through the AWS Bridge (opt-in only)';

    public function handle(Notifier $notifier): int
    {
        $channel = \App\Models\Channel::find((int) $this->argument('channel'));
        $contact = \App\Models\Contact::find((int) $this->argument('contact'));
        if (!$channel || !$contact) { $this->error('Channel or contact not found.'); return self::FAILURE; }
        [$type, $id] = array_pad(explode(':', (string) $this->argument('reference'), 2), 2, '');
        $result = $notifier->send($channel, $contact, (string) $this->argument('kind'), $this->option('subject'), (string) $this->argument('text'), $type, $id);
        $this->line(json_encode(array_intersect_key($result, array_flip(['success', 'state', 'error', 'duplicate'])), JSON_UNESCAPED_SLASHES));
        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
