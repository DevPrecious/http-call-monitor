<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('http-call-monitor.storage.connection');
    }

    public function up(): void
    {
        Schema::create(config('http-call-monitor.storage.table'), function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->string('method', 10);
            $table->string('path', 1000);
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('error')->nullable();
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index(['host', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('http-call-monitor.storage.table'));
    }
};
