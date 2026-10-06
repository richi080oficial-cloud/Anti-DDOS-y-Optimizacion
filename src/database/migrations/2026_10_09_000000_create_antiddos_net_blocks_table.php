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

        // Redes enteras bloqueadas: un operador (ASN) o un pais, con sus rangos IPv4.
        Schema::create('antiddos_net_blocks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 8);               // asn | country
            $table->string('value', 16);             // 199585 | PL
            $table->string('name')->nullable();      // titular del ASN o nombre del pais
            $table->string('reason')->nullable();
            $table->longText('prefixes')->nullable(); // JSON: rangos CIDR IPv4 ya agregados
            $table->unsignedInteger('prefix_count')->default(0);
            $table->unsignedBigInteger('ip_count')->default(0);
            $table->string('source', 16)->nullable(); // de donde salieron los rangos
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
