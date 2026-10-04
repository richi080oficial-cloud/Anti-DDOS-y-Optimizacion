<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('antiddos_settings')) {
            Schema::create('antiddos_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('antiddos_blocks')) {
            Schema::create('antiddos_blocks', function (Blueprint $table) {
                $table->increments('id');
                $table->string('ip', 64)->unique();
                $table->string('reason')->nullable();
                // auto = escudo web | manual = admin/CLI | api = otro sistema
                $table->string('source', 16)->default('manual');
                $table->unsignedInteger('strikes')->default(1);
                $table->unsignedInteger('user_id')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('antiddos_events')) {
            Schema::create('antiddos_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('type', 32)->index();
                $table->string('ip', 64)->nullable();
                $table->string('message', 500);
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        if (!Schema::hasTable('antiddos_optimizations')) {
            Schema::create('antiddos_optimizations', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('server_id')->unique();
                $table->string('profile', 32);
                $table->text('original_startup')->nullable();
                $table->json('files')->nullable();
                $table->timestamps();

                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('antiddos_optimizations');
        Schema::dropIfExists('antiddos_events');
        Schema::dropIfExists('antiddos_blocks');
        Schema::dropIfExists('antiddos_settings');
    }
};
