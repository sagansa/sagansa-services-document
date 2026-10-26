<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat ke-11 tabel domain surat pada koneksi `mysql_document`.
 *
 * Service ini TIDAK memiliki migration domain (skema dikelola
 * services/migration). Untuk testing, koneksi `mysql_document` saat
 * APP_ENV=testing ditukar ke file sqlite `database_test_document.sqlite`
 * (config/database.php); trait ini menirukan skema persis dari
 * services/migration/database/migrations/2026_10_26_000{001,002}_create_document_*_tables.php
 * di koneksi tersebut.
 *
 * Pemakaian (dipakai ulang semua feature test domain surat):
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->createDocumentSchema();
 *     }
 *
 * Skema di-drop lalu dibuat ulang setiap pemanggilan sehingga setiap test
 * mulai dari kondisi bersih (file sqlite dipakai bersama antar test dalam
 * satu proses phpunit).
 */
trait CreatesDocumentSchema
{
    protected function createDocumentSchema(): void
    {
        $connection = DB::connection('mysql_document');
        $isSqlite = $connection->getDriverName() === 'sqlite';

        // Matikan FK sementara agar DROP TABLE aman (khususnya self-FK
        // issuances.replaced_by_id pada tabel yang masih berisi data).
        if ($isSqlite) {
            $connection->statement('PRAGMA foreign_keys = OFF');
        }

        $this->dropDocumentTables();
        $this->createDocumentTables();

        if ($isSqlite) {
            $connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Nama ke-11 tabel domain surat, urut anak-dulu (aman untuk drop).
     *
     * @return list<string>
     */
    protected function documentTables(): array
    {
        return [
            'verification_hits',
            'audit_logs',
            'signing_requests',
            'issuances',
            'document_attachments',
            'documents',
            'templates',
            'signers',
            'parties',
            'number_sequences',
            'letter_types',
        ];
    }

    protected function dropDocumentTables(): void
    {
        $schema = Schema::connection('mysql_document');

        foreach ($this->documentTables() as $table) {
            $schema->dropIfExists($table);
        }
    }

    protected function createDocumentTables(): void
    {
        $schema = Schema::connection('mysql_document');

        // 1. letter_types — jenis surat + definisi field khusus per jenis.
        $schema->create('letter_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->json('specific_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. templates — template isi surat (HTML) per jenis.
        $schema->create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_type_id')
                ->constrained('letter_types')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name');
            $table->longText('body_html');
            $table->json('placeholders')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. number_sequences — counter nomor per (jenis, unit, tahun),
        //    baris counter murni TANPA timestamps.
        $schema->create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('letter_type_code', 8);
            $table->string('unit', 16);
            $table->integer('year');
            $table->integer('last_seq')->default(0);

            $table->unique(['letter_type_code', 'unit', 'year']);
        });

        // 4. parties — pihak eksternal; created_from_document_id tanpa FK
        //    (dokumen dibuat setelah pihak bisa lahir dari isian bebas).
        $schema->create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('identity_no')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_from_document_id')->nullable();
            $table->timestamps();
        });

        // 5. signers — penandatangan internal; user_id merujuk users di DB
        //    sagansa_user, tanpa FK lintas database.
        $schema->create('signers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['direktur', 'komisaris', 'pemegang_saham']);
            $table->string('display_name');
            $table->string('title')->nullable();
            $table->string('signature_asset_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });

        // 6. documents — surat itu sendiri, PK uuid.
        $schema->create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('letter_type_id')
                ->constrained('letter_types')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->uuid('company_id')->nullable()->index();
            $table->string('number')->unique();
            $table->integer('seq');
            $table->string('unit', 16);
            $table->integer('year');
            $table->string('subject');
            $table->date('date');
            $table->longText('body_html')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('classification', ['umum', 'rahasia'])->default('umum');
            $table->enum('status', ['draft', 'menunggu_ttd', 'terbit', 'dicabut', 'dibatalkan'])->default('draft');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['status', 'year']);
        });

        // 7. document_attachments — lampiran per surat.
        $schema->create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('label');
            $table->integer('sort')->default(0);
            $table->string('path');
            $table->char('sha256', 64);
            $table->string('mime');
            $table->integer('size');
            $table->timestamps();

            $table->index('document_id');
        });

        // 8. issuances — hasil penerbitan (berkas final + hash);
        //    replaced_by_id self-FK ke penerbitan pengganti.
        $schema->create('issuances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->enum('method', ['a', 'b', 'c', 'd']);
            $table->enum('method_label', ['elektronik', 'ttd_elektronik_resmi', 'manual']);
            $table->string('file_path');
            $table->char('file_sha256', 64);
            $table->char('attachments_sha256', 64)->nullable();
            $table->integer('file_size');
            $table->integer('pages')->nullable();
            $table->string('note')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $table->foreign('replaced_by_id')
                ->references('id')
                ->on('issuances')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('finalized_by');
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->index(['document_id', 'is_active']);
        });

        // 9. signing_requests — permintaan persetujuan penandatangan,
        //    TANPA timestamps (requested_at/acted_at cukup).
        $schema->create('signing_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('signer_id')
                ->constrained('signers')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->enum('status', ['diminta', 'disetujui', 'ditolak'])->default('diminta');
            $table->timestamp('requested_at');
            $table->timestamp('acted_at')->nullable();
            $table->string('note')->nullable();

            $table->index(['signer_id', 'status']);
        });

        // 10. audit_logs — append-only, hanya created_at, document_id
        //     TANPA FK (jejak dipertahankan meski surat dihapus).
        $schema->create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('document_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 32);
            $table->json('payload')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // 11. verification_hits — append-only, hanya created_at.
        $schema->create('verification_hits', function (Blueprint $table) {
            $table->id();
            $table->uuid('document_id')->index();
            $table->ipAddress('ip')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
