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
        Schema::table('discussions', function (Blueprint $table) {
            $table->unsignedBigInteger('moderator_id')->nullable()->after('user_id');
            $table->foreign('moderator_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('replies', function (Blueprint $table) {
            $table->unsignedBigInteger('moderator_id')->nullable()->after('user_id');
            $table->foreign('moderator_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
            $table->dropForeign(['moderator_id']);
            $table->dropColumn('moderator_id');
        });

        Schema::table('replies', function (Blueprint $table) {
            $table->dropForeign(['moderator_id']);
            $table->dropColumn('moderator_id');
        });
    }
};
