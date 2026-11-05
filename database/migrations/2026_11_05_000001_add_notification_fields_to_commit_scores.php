<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commit_scores', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->after('score');
            $table->timestamp('notified_at')->nullable()->index()->after('committed_at');
        });
    }

    public function down(): void
    {
        Schema::table('commit_scores', function (Blueprint $table) {
            $table->dropColumn(['reason', 'notified_at']);
        });
    }
};
