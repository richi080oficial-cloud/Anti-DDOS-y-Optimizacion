<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('antiddos_net_blocks')) {
            return;
        }

        Schema::create('antiddos_net_blocks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 8);
            $table->string('value', 16);
            $table->string('name')->nullable();
            $table->string('reason')->nullable();
            $table->longText('prefixes')->nullable();
            $table->unsignedInteger('prefix_count')->default(0);
            $table->unsignedBigInteger('ip_count')->default(0);
            $table->string('source', 16)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antiddos_net_blocks');
    }
};
