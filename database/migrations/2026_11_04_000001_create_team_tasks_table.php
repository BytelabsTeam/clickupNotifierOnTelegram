<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            // Telegram numeric user id is the only identity key. Usernames are display/mention only.
            $table->unsignedBigInteger('telegram_user_id')->nullable()->unique();
            $table->string('telegram_username')->nullable()->index();
            $table->string('github_login')->nullable()->index();
            $table->string('display_name');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('team_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo')->index();
            $table->string('priority', 20)->default('normal');
            $table->unsignedBigInteger('assignee_telegram_id')->index();
            $table->string('assignee_username')->nullable();
            $table->string('assignee_name')->nullable();
            $table->unsignedBigInteger('created_by_telegram_id');
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->boolean('is_code')->default(false);
            $table->unsignedInteger('score')->default(0);
            $table->string('score_reason')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index(['assignee_telegram_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tasks');
        Schema::dropIfExists('team_members');
    }
};
