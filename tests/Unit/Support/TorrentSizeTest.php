<?php

namespace Tests\Unit\Support;

use App\Support\TorrentSize;
use PHPUnit\Framework\TestCase;

class TorrentSizeTest extends TestCase
{
    public function test_parses_si_and_iec_units_into_bytes(): void
    {
        $cases = [
            '1 KB' => 1_000,
            '1 MB' => 1_000_000,
            '1.5 GB' => 1_500_000_000,
            '2 TB' => 2_000_000_000_000,
            '1 KiB' => 1_024,
            '1 MiB' => 1_048_576,
            '1.5 GiB' => 1_610_612_736,
            '1 TiB' => 1_099_511_627_776,
            "1.5\u{00A0}GB" => 1_500_000_000,
            "1.5\u{202F}GB" => 1_500_000_000,
        ];

        foreach ($cases as $source => $expected) {
            $parsed = TorrentSize::parse($source);
            $this->assertSame($expected, $parsed['sizeBytes'], $source);
            $this->assertFalse($parsed['sizeParseError'], $source);
        }
    }

    public function test_declared_or_missing_unknown_size_is_not_a_parse_error(): void
    {
        foreach ([null, '', '   ', 'n/a', 'N/A'] as $source) {
            $parsed = TorrentSize::parse($source);
            $this->assertNull($parsed['sizeBytes']);
            $this->assertFalse($parsed['sizeParseError']);
        }
    }

    public function test_non_empty_unparseable_source_is_a_parse_error(): void
    {
        foreach (['unknown', '1.5', 'GB', '1.5 XB'] as $source) {
            $parsed = TorrentSize::parse($source);
            $this->assertNull($parsed['sizeBytes']);
            $this->assertTrue($parsed['sizeParseError'], $source);
        }
    }

    public function test_formats_bytes_for_human_presentation_without_locale_grouping(): void
    {
        $this->assertSame('n/a', TorrentSize::format(null));
        $this->assertSame('0 B', TorrentSize::format(0));
        $this->assertSame('1.5 KB', TorrentSize::format(1_500));
        $this->assertSame('1.5 MB', TorrentSize::format(1_500_000));
        $this->assertSame('1.5 GB', TorrentSize::format(1_500_000_000));
    }
}
