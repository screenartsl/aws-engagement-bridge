<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MuseRelay\AwsEngagementBridge\Core\Schema;

// Email/SMS through the AWS Engagement Bridge. Apply only after authorization, with --path.
return new class extends Migration {
    public function up(): void { Schema::createNotifications(DB::connection()->getSchemaBuilder()); }
    public function down(): void { DB::connection()->getSchemaBuilder()->dropIfExists('aws_bridge_notifications'); }
};
