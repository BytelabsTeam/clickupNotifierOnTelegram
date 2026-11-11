<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('telegram_username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('punched_in_at');
            $table->timestamp('punched_out_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('source', 32)->default('command');
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->boolean('auto_closed')->default(false);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamps();

            $table->index(['telegram_user_id', 'punched_out_at']);
            $table->index('work_date');
            $table->index('punched_in_at');
        });

        Schema::create('punch_stat_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('created_by_telegram_user_id');
            $table->string('created_by_username')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('punch_stat_tokens');
        Schema::dropIfExists('work_sessions');
    }
};
