<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_scoring_docs', function (Blueprint $table) {
            $table->id();
            $table->string('repo')->unique();
            $table->text('content');
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('commit_scores', function (Blueprint $table) {
            $table->id();
            $table->string('sha', 64);
            $table->string('repo');
            $table->string('login')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('score');
            $table->timestamp('committed_at')->nullable();
            $table->string('month', 7)->index();
            $table->timestamps();

            $table->unique(['sha', 'repo']);
            $table->index(['month', 'committed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_scores');
        Schema::dropIfExists('project_scoring_docs');
    }
};
