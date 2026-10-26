<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_scores', function (Blueprint $table) {
            $table->id();
            $table->string('task_id')->unique();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->string('task_name');
            $table->text('task_description')->nullable();
            $table->boolean('is_code')->default(false);
            $table->unsignedInteger('score')->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('month', 7)->index();
            $table->timestamps();

            $table->index(['month', 'completed_at']);
            $table->index(['is_code', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_scores');
    }
};
