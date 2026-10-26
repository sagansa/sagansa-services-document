<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Saat APP_ENV=testing koneksi mysql_auth/mysql_document ditukar ke
        // file sqlite (config/database.php) yang di-gitignore — file tidak
        // tersedia di checkout/CI baru, jadi siapkan file + migrasinya di
        // sini agar suite tidak gagal dengan
        // SQLiteDatabaseDoesNotExistException.
        foreach (['database_test.sqlite', 'database_test_document.sqlite'] as $file) {
            $sqlite = database_path($file);
            if (! is_file($sqlite)) {
                touch($sqlite);
            }
        }

        Artisan::call('migrate', ['--force' => true]);
    }
}
