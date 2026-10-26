<?php

namespace Tests\Feature;

use App\Services\QrBuilder;
use Tests\TestCase;

/**
 * QrBuilder::build() — PNG QR untuk verifikasi dokumen.
 *
 * Decoding PNG QR di test butuh library tambahan, jadi sesuai brief:
 * QR image diuji properti binernya (magic byte \x89PNG, ukuran >1KB,
 * dimensi 300x300 dari header IHDR), sedangkan URL payload diuji
 * langsung lewat method publik payload().
 */
class QrBuilderTest extends TestCase
{
    private QrBuilder $builder;

    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new QrBuilder;
    }

    public function test_build_returns_png_binary(): void
    {
        $bin = $this->builder->build(self::UUID);

        $this->assertSame("\x89PNG", substr($bin, 0, 4), 'output harus diawali magic byte PNG');
    }

    public function test_build_output_is_larger_than_1kb(): void
    {
        $bin = $this->builder->build(self::UUID);

        $this->assertGreaterThan(1000, strlen($bin), 'PNG QR 300px harus lebih dari 1KB');
    }

    /**
     * Dimensi gambar dibaca langsung dari header IHDR (big-endian
     * width offset 16, height offset 20) — renderer harus menghasilkan
     * gambar 300x300 tanpa perlu decode penuh.
     */
    public function test_build_renders_300_by_300_image(): void
    {
        $bin = $this->builder->build(self::UUID);

        $width = unpack('N', substr($bin, 16, 4))[1];
        $height = unpack('N', substr($bin, 20, 4))[1];

        $this->assertSame(300, $width);
        $this->assertSame(300, $height);
    }

    public function test_payload_concatenates_domain_config_with_uuid(): void
    {
        config(['document.domain' => 'https://verify.example.test']);

        $this->assertSame(
            'https://verify.example.test/verify/'.self::UUID,
            $this->builder->payload(self::UUID),
        );
    }

    public function test_payload_respects_domain_config_changes(): void
    {
        config(['document.domain' => 'https://satu.example.test']);
        $first = $this->builder->payload(self::UUID);

        config(['document.domain' => 'https://dua.example.test']);
        $second = $this->builder->payload(self::UUID);

        $this->assertSame('https://satu.example.test/verify/'.self::UUID, $first);
        $this->assertSame('https://dua.example.test/verify/'.self::UUID, $second);
    }

    public function test_document_config_has_expected_defaults(): void
    {
        // DOCUMENT_DISK tidak di-set di phpunit.xml/.env.testing →
        // default dari config/document.php yang berlaku.
        $this->assertSame('documents', config('document.disk'));
        $this->assertSame(30, config('document.verify_rate_limit'));
        $this->assertNotSame('', (string) config('document.domain'));
    }
}
