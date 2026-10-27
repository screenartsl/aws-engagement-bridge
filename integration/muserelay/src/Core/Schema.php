<?php
declare(strict_types=1);
namespace MuseRelay\AwsEngagementBridge\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class Schema
{
    public static function create(Builder $schema): void
    {
        $schema->create('aws_bridge_bindings', function (Blueprint $t): void {
            $t->string('id', 80)->primary();
            $t->string('channel_ref', 80)->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('channel_id')->unique();
            $t->unsignedBigInteger('agent_id');
            $t->string('account_id', 12);
            $t->string('region', 40);
            $t->string('waba_id', 40);
            $t->string('meta_phone_id', 40);
            $t->string('aws_phone_arn');
            $t->text('allowed_recipients');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('daily_inbound_limit')->default(40);
            $t->unsignedInteger('daily_outbound_limit')->default(20);
            $t->unsignedBigInteger('lease_until')->default(0);
            $t->string('lease_token', 64)->nullable();
            $t->unique(['account_id', 'region', 'waba_id', 'meta_phone_id'], 'aws_bridge_identity_unique');
        });
        $schema->create('aws_bridge_nonces', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->unsignedBigInteger('expires_at')->index();
        });
        $schema->create('aws_bridge_events', function (Blueprint $t): void {
            $t->bigIncrements('sequence');
            $t->string('id', 64)->unique();
            $t->string('binding_id', 80)->index();
            $t->string('kind', 12);
            $t->string('digest', 64);
            $t->text('payload');
            $t->string('state', 20)->default('pending')->index();
            $t->unsignedBigInteger('message_id')->nullable()->index();
            $t->unsignedBigInteger('conversation_id')->nullable();
            $t->unsignedBigInteger('created_epoch')->index();
            $t->unsignedBigInteger('event_timestamp');
            $t->unsignedBigInteger('lease_until')->default(0);
            $t->string('lease_token', 64)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code', 80)->nullable();
        });
        $schema->create('aws_bridge_outbox', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('binding_id', 80)->index();
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('source_message_id')->nullable()->index();
            $t->text('payload');
            $t->string('state', 20)->default('pending')->index();
            $t->string('provider_message_id')->nullable()->index();
            $t->string('delivery_status', 20)->nullable();
            $t->unsignedBigInteger('created_epoch')->index();
            $t->unsignedBigInteger('lease_until')->default(0);
            $t->string('lease_token', 64)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code', 80)->nullable();
        });
        $schema->create('aws_bridge_actions', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('event_id', 64)->index();
            $t->string('tool_name', 100);
            $t->text('result')->nullable();
        });
    }

    /** 0.5.16: per-conversation ordering key (Store::threadKey), so different conversations are processed in parallel. */
    public static function addThreadKey(Builder $schema): void
    {
        $schema->table('aws_bridge_events', function (Blueprint $t): void {
            $t->string('thread_key', 64)->nullable();
            $t->index(['binding_id', 'thread_key', 'state'], 'aws_bridge_events_thread_idx');
        });
    }

    /** Email/SMS sends: one row per notifyId (operation + kind + recipient). No message text, recipient only as a hash. */
    public static function createNotifications(Builder $schema): void
    {
        $schema->create('aws_bridge_notifications', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('binding_id', 80)->index();
            $t->unsignedBigInteger('organization_id')->index();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('kind', 8);
            $t->string('recipient_hash', 64);
            $t->string('reference_type', 20);
            $t->string('reference_id', 64);
            $t->string('state', 20)->default('pending')->index();
            $t->string('provider_message_id')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->unsignedBigInteger('created_epoch')->index();
            $t->unsignedBigInteger('updated_epoch');
            $t->index(['reference_type', 'reference_id']);
        });
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists('aws_bridge_notifications');
        foreach (['aws_bridge_actions', 'aws_bridge_outbox', 'aws_bridge_events', 'aws_bridge_nonces', 'aws_bridge_bindings'] as $table) {
            $schema->dropIfExists($table);
        }
    }
}
