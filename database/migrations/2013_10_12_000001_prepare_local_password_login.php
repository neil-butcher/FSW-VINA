<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!config('hts-appteam.local_password_login')) {
            return;
        }

        $hasSolisId = Schema::hasColumn('users', 'solis_id');
        $hasPassword = Schema::hasColumn('users', 'password');
        $hasRememberToken = Schema::hasColumn('users', 'remember_token');

        Schema::table('users', function (Blueprint $table) use ($hasSolisId, $hasPassword, $hasRememberToken) {
            if (!$hasSolisId) {
                $table->string('solis_id')->nullable()->unique();
            }
            if (!$hasPassword) {
                $table->string('password')->nullable();
            }
            if (!$hasRememberToken) {
                $table->rememberToken();
            }
        });
    }

    public function down(): void
    {
        // The columns may have existed before this migration; retain user credentials on rollback.
    }
};
