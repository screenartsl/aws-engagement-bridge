<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MuseRelay\AwsEngagementBridge\Core\Schema;

// Per-conversation ordering key: different conversations of one AWS channel are processed in parallel. Apply with --path.
return new class extends Migration {
    public function up(): void { Schema::addThreadKey(DB::connection()->getSchemaBuilder()); }
    public function down(): void
    {
        DB::connection()->getSchemaBuilder()->table('aws_bridge_events', function ($t): void {
            $t->dropIndex('aws_bridge_events_thread_idx');
            $t->dropColumn('thread_key');
        });
    }
};
