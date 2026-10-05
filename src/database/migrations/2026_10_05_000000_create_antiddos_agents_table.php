<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('antiddos_agents')) {
            Schema::create('antiddos_agents', function (Blueprint $table) {
                $table->increments('id');
                $table->string('host', 120)->unique();
                $table->json('ips')->nullable();
                $table->string('version', 32)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('antiddos_agents');
    }
};
