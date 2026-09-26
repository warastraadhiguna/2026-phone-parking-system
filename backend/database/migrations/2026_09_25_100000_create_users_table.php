<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity accounts for staff (admin web) and attendants (mobile).
 * Users are never hard-deleted: they are deactivated (status), so audit references stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('name', 150);
            $table->string('email', 191)->nullable()->unique();
            $table->string('password');
            $table->string('account_type', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            $table->index(['account_type', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('STAFF', 'ATTENDANT')),
                ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'SUSPENDED')),
                ADD CONSTRAINT users_username_format_check CHECK (username ~ '^[a-z0-9][a-z0-9._-]{2,49}$'),
                ADD CONSTRAINT users_email_lowercase_check CHECK (email IS NULL OR email = lower(email))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
