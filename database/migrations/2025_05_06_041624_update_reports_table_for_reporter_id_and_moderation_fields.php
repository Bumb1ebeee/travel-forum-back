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
        Schema::table('reports', function (Blueprint $table) {
            // Переименовываем user_id в reporter_id
            $table->renameColumn('user_id', 'reporter_id');
            // Добавляем поля status и moderator_comment
            $table->string('status')->default('pending')->after('reportable_type');
            $table->text('moderator_comment')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('reporter_id', 'user_id');
            $table->dropColumn(['status', 'moderator_comment']);
        });
    }
};
