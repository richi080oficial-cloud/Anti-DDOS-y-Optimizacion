<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('antiddos_metrics')) {
            Schema::create('antiddos_metrics', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('host', 120);
                $table->timestamp('recorded_at')->useCurrent();
                $table->unsignedBigInteger('dropped')->default(0);
                $table->unsignedBigInteger('syn')->default(0);
                $table->unsignedBigInteger('tcp_pkts')->default(0);
                $table->unsignedBigInteger('udp_pkts')->default(0);
                $table->unsignedInteger('bans')->default(0);

                $table->index(['host', 'recorded_at']);
                $table->index('recorded_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('antiddos_metrics');
    }
};
