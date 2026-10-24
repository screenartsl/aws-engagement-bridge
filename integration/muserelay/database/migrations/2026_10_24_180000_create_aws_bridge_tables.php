<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MuseRelay\AwsEngagementBridge\Core\Schema;

return new class extends Migration {
    public function up(): void { Schema::create(DB::connection()->getSchemaBuilder()); }
    public function down(): void { Schema::drop(DB::connection()->getSchemaBuilder()); }
};
