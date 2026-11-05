<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (report type, period key). Unique key makes scheduled reports idempotent.
        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('period_key', 32);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
