<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel auth (users + personal_access_tokens) pada koneksi
 * `mysql_auth`.
 *
 * Saat APP_ENV=testing koneksi mysql_auth ditukar ke file sqlite
 * `database_test.sqlite` (config/database.php) yang hanya di-touch oleh
 * tests/TestCase.php — kosong, tanpa tabel. Sanctum::actingAs($user) sendiri
 * tidak membaca DB token, tapi User::factory()->create() menulis ke tabel
 * users di koneksi itu, jadi tabelnya harus ada untuk feature test API.
 * Skema menirukan migration 0001_01_01_000000_create_users_table (users)
 * dan migration Sanctum (personal_access_tokens, untuk kewajaran bila test
 * kelak memakai createToken()).
 *
 * Pemakaian:
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->createAuthSchema();
 *     }
 */
trait CreatesAuthSchema
{
    protected function createAuthSchema(): void
    {
        $schema = Schema::connection('mysql_auth');

        $schema->dropIfExists('personal_access_tokens');
        $schema->dropIfExists('users');

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $schema->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
