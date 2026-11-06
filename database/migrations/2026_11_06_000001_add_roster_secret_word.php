<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_students', function (Blueprint $table) {
            $table->string('claim_word_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_students', function (Blueprint $table) {
            $table->dropColumn('claim_word_hash');
        });
    }
};
