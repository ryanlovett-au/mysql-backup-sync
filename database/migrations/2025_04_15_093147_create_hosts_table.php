<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->string('db_host');
            $table->string('db_port')->default('3306');
            $table->string('db_username');
            $table->string('db_password');
            $table->boolean('db_use_ssl')->default(0);
            $table->boolean('use_ssh_tunnel')->default(0);
            $table->string('ssh_host');
            $table->string('ssh_port')->default(22);
            $table->string('ssh_username');
            $table->string('ssh_password')->nullable();
            $table->string('ssh_public_key_path')->nullable();
            $table->string('ssh_private_key_path')->nullable();
            $table->string('ssh_private_key_passphrase')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hosts');
    }
};
