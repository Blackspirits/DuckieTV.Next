<?php

namespace Tests\Unit\Support;

use App\Support\MagnetUri;
use PHPUnit\Framework\TestCase;

class MagnetUriTest extends TestCase
{
    private const string HEX_HASH = '00112233445566778899aabbccddeeff00112233';

    private const string BASE32_HASH = 'AAISEM2EKVTHPCEZVK54ZXPO74ABCIRT';

    public function test_exact_hex_is_canonicalized_to_lowercase(): void
    {
        $this->assertSame(self::HEX_HASH, MagnetUri::normalizeInfoHash(strtoupper(self::HEX_HASH)));
        $this->assertSame(self::HEX_HASH, MagnetUri::normalizeInfoHash(self::HEX_HASH));
    }

    public function test_rfc4648_base32_is_case_insensitive_and_zero_padded(): void
    {
        $upper = MagnetUri::normalizeInfoHash(self::BASE32_HASH);
        $lower = MagnetUri::normalizeInfoHash(strtolower(self::BASE32_HASH));

        $this->assertSame(self::HEX_HASH, $upper);
        $this->assertSame($upper, $lower);
        $this->assertSame(40, strlen((string) $upper));
        $this->assertStringStartsWith('00', (string) $upper);
    }

    public function test_extracts_percent_encoded_btih_exact_topic_instead_of_decoy_text(): void
    {
        $decoy = str_repeat('f', 40);
        $magnet = 'magnet:?dn='.$decoy.'&xt=urn%3Abtih%3A'.rawurlencode(strtolower(self::BASE32_HASH));

        $this->assertSame(self::HEX_HASH, MagnetUri::extractInfoHash($magnet));
    }

    public function test_extracts_hex_btih_from_magnet(): void
    {
        $magnet = 'magnet:?xt=urn:btih:'.strtoupper(self::HEX_HASH).'&dn=Example';

        $this->assertSame(self::HEX_HASH, MagnetUri::extractInfoHash($magnet));
    }

    public function test_rejects_invalid_or_ambiguous_identity(): void
    {
        $this->assertNull(MagnetUri::normalizeInfoHash('abc'));
        $this->assertNull(MagnetUri::normalizeInfoHash('AAISEM2EKVTHPCEZVK54ZXPO74ABCIR1'));
        $this->assertNull(MagnetUri::extractInfoHash('magnet:?xt=urn:sha1:'.self::HEX_HASH));
        $this->assertNull(MagnetUri::extractInfoHash('magnet:?dn='.self::HEX_HASH));
    }
}
