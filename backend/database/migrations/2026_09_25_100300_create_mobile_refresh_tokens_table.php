<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotating refresh tokens for the Android app (ADR-0005).
 *
 * One login creates a token "family". Each refresh marks the presented token used and issues
 * its replacement in the same family. Presenting a used token outside the grace window revokes
 * the whole family (reuse detection). Only SHA-256 hashes are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('family_id');
            $table->char('token_hash', 64)->unique();
            $table->uuid('device_uuid');
            $table->foreignId('access_token_id')->nullable()
                ->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()
                ->constrained('mobile_refresh_tokens')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->ipAddress('created_ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('family_id');
            $table->index(['user_id', 'device_uuid']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mobile_refresh_tokens
                ADD CONSTRAINT mobile_refresh_tokens_revocation_check
                    CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_refresh_tokens');
    }
};
