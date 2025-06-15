<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFilePathToMediaContent extends Migration
{
    public function up()
    {
        Schema::table('media_content', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('file_id');
        });
    }

    public function down()
    {
        Schema::table('media_content', function (Blueprint $table) {
            $table->dropColumn('file_path');
        });
    }
}
