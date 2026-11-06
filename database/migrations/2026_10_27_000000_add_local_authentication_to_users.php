<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may already have fields from the university package.
        foreach (['password', 'remember_token', 'solis_id', 'allowed_attributes'] as $column) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    match ($column) {
                        'remember_token' => $table->rememberToken(),
                        'allowed_attributes' => $table->text($column)->nullable(),
                        default => $table->string($column)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        // Retain credentials and legacy fields on rollback to avoid losing user data.
    }
};
