<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('summarizer_runs', function (Blueprint $table) {
            $table->integer('thread_id')->nullable()->after('chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('summarizer_runs', function (Blueprint $table) {
            $table->dropColumn('thread_id');
        });
    }
};
