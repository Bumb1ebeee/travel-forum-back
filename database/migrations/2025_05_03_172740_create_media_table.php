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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('type'); // Тип медиа: text, image, video, music, map
            $table->string('file_type')->nullable(); // Формат файла, например, image/jpeg
            $table->timestamps();
        });

        Schema::create('media_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained()->onDelete('cascade');
            $table->string('content_type')->index(); // Тип контента: text, image, video, music, map
            $table->text('text_content')->nullable(); // Для текста
            $table->text('image_url')->nullable(); // Для изображения
            $table->text('video_url')->nullable(); // Для видео
            $table->text('music_url')->nullable(); // Для музыки
            $table->json('map_points')->nullable(); // Для карты (массив координат)
            $table->unsignedInteger('order')->default(0); // Порядок отображения
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_content');
        Schema::dropIfExists('media');
    }
};
