<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'tcp_bytes', 'udp_bytes', 'drop_bytes',
        'd_ban', 'd_mal', 'd_icmp', 'd_syn', 'd_udp', 'd_conn', 'd_web',
        'rx_bytes', 'rx_pkts', 'tx_bytes', 'tx_pkts',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('antiddos_metrics')) {
            return;
        }

        Schema::table('antiddos_metrics', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (!Schema::hasColumn('antiddos_metrics', $column)) {
                    $table->unsignedBigInteger($column)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('antiddos_metrics')) {
            return;
        }

        Schema::table('antiddos_metrics', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('antiddos_metrics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
