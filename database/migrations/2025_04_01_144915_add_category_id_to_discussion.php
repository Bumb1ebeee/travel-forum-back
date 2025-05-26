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
            // Проверяем, существует ли столбец, чтобы избежать дублирования
            if (!Schema::hasColumn('discussions', 'category_id')) {
                $table->foreignId('category_id')
                    ->constrained()
                    ->onDelete('cascade')
                    ->after('user_id')->nullable(); // Опционально: указываем, после какого столбца добавить
            }
            $table->unsignedBigInteger('views')->default(0)->after('description');
            $table->string('map_start')->nullable(); // Начальная точка
            $table->string('map_end')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
            // Удаляем внешний ключ и столбец
            $table->dropColumn('category_id');
            $table->dropColumn('views');
            $table->dropColumn('map_start');
            $table->dropColumn('map_end');
        });
    }
};
