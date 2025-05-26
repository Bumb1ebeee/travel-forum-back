<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_content', function (Blueprint $table) {
            $table->string('file_id')->nullable()->after('map_points');
        });
    }

    public function down(): void
    {
        Schema::table('media_content', function (Blueprint $table) {
            $table->dropColumn('file_id');
        });
    }
};
