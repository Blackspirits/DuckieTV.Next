<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('series')
            ->select(['id', 'firstaired', 'added'])
            ->orderBy('id')
            ->chunkById(100, function ($series): void {
                foreach ($series as $serie) {
                    $updates = [];

                    foreach (['firstaired', 'added'] as $column) {
                        $value = $serie->{$column};

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $updates[$column] = $this->normalizeMilliseconds(
                            $value,
                            (int) $serie->id,
                            $column
                        );
                    }

                    if ($updates !== []) {
                        DB::table('series')
                            ->where('id', $serie->id)
                            ->update($updates);
                    }
                }
            });

        Schema::table('series', function (Blueprint $table): void {
            $table->bigInteger('firstaired')->nullable()->change();
            $table->bigInteger('added')->nullable()->change();
            $table->string('lastupdated', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->date('firstaired')->nullable()->change();
            $table->date('added')->nullable()->change();
            $table->bigInteger('lastupdated')->nullable()->change();
        });
    }

    private function normalizeMilliseconds(mixed $value, int $id, string $column): int
    {
        $text = trim((string) $value);

        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }

        if (preg_match(
            '/^(-?\d{4,})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/',
            $text,
            $matches
        ) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $hour = isset($matches[4]) ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) ? (int) $matches[5] : 0;
            $second = isset($matches[6]) ? (int) $matches[6] : 0;

            $this->assertValidCivilDate($year, $month, $day, $hour, $minute, $second, $id, $column);

            $seconds = $this->utcTimestampFromCivil($year, $month, $day, $hour, $minute, $second);

            // The broken Laravel date cast interpreted the intended millisecond
            // value as seconds and formatted it as an astronomical year. Reversing
            // that civil date yields the exact original millisecond value.
            if (abs($year) > 9999) {
                return $seconds;
            }

            return $seconds * 1000;
        }

        try {
            return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->getTimestamp() * 1000;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Unable to normalize series.{$column} for row {$id}: {$text}",
                0,
                $e
            );
        }
    }

    private function assertValidCivilDate(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        int $id,
        string $column
    ): void {
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        $days = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        if ($month < 1 || $month > 12
            || $day < 1 || $day > $days[$month - 1]
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $second < 0 || $second > 59) {
            throw new \RuntimeException(
                "Invalid series.{$column} civil date for row {$id}"
            );
        }
    }

    private function utcTimestampFromCivil(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): int {
        $adjustedYear = $year - ($month <= 2 ? 1 : 0);
        $era = intdiv(
            $adjustedYear >= 0 ? $adjustedYear : $adjustedYear - 399,
            400
        );
        $yearOfEra = $adjustedYear - ($era * 400);
        $monthPrime = $month + ($month > 2 ? -3 : 9);
        $dayOfYear = intdiv((153 * $monthPrime) + 2, 5) + $day - 1;
        $dayOfEra = ($yearOfEra * 365)
            + intdiv($yearOfEra, 4)
            - intdiv($yearOfEra, 100)
            + $dayOfYear;

        $daysSinceEpoch = ($era * 146097) + $dayOfEra - 719468;

        return ($daysSinceEpoch * 86400)
            + ($hour * 3600)
            + ($minute * 60)
            + $second;
    }
};
