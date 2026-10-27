<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_starts', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('telegram_username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['work_date', 'telegram_user_id']);
            $table->index('work_date');
        });

        Schema::create('morning_checkin_messages', function (Blueprint $table) {
            $table->id();
            $table->date('work_date')->unique();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('morning_checkin_messages');
        Schema::dropIfExists('work_starts');
    }
};
