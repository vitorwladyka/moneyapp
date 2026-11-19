<?php
declare(strict_types=1);

namespace Moneyapp\Tests;

use Moneyapp\Core\DateException;
use Moneyapp\Core\Dates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dates::class)]
final class DatesTest extends TestCase
{
    public function test_now_utc_is_utc_midnight_truncated(): void
    {
        $now = Dates::nowUtc();
        $this->assertSame('UTC', $now->getTimezone()->getName());
        // Sem microssegundos.
        $this->assertSame(0, (int) $now->format('u'));
    }

    public function test_now_utc_iso_format(): void
    {
        $iso = Dates::nowUtcIso();
        $this->assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $iso);
    }

    public function test_parse_date_dd_mm_yyyy(): void
    {
        $d = Dates::parseDate('01/03/2025');
        $this->assertSame('2025-03-01', Dates::toIso($d));
        $this->assertSame('2025-03-01 00:00:00', Dates::toIsoTs($d));
        $this->assertSame('01/03/2025', Dates::formatDate($d));
    }

    public function test_parse_date_iso(): void
    {
        $d = Dates::parseDate('2025-03-01');
        $this->assertSame('2025-03-01', Dates::toIso($d));
    }

    public function test_parse_date_rejects_invalid_format(): void
    {
        $this->expectException(DateException::class);
        Dates::parseDate('1/3/2025');
    }

    public function test_parse_date_rejects_two_digit_year(): void
    {
        $this->expectException(DateException::class);
        Dates::parseDate('01/03/25');
    }

    public function test_parse_date_rejects_impossible_calendar(): void
    {
        $this->expectException(DateException::class);
        Dates::parseDate('30/02/2025');
    }

    public function test_parse_date_rejects_non_string(): void
    {
        $this->expectException(DateException::class);
        Dates::parseDate(123);
    }

    public function test_format_date_rejects_non_midnight(): void
    {
        $d = new \DateTimeImmutable('2025-03-01 14:30:00', new \DateTimeZone('UTC'));
        $this->expectException(DateException::class);
        Dates::formatDate($d);
    }

    public function test_from_iso_round_trip(): void
    {
        $d = Dates::fromIso('2025-03-01');
        $this->assertSame('2025-03-01', Dates::toIso($d));
    }

    public function test_from_iso_rejects_invalid(): void
    {
        $this->expectException(DateException::class);
        Dates::fromIso('2025-13-01');
    }

    public function test_from_iso_ts_round_trip(): void
    {
        $d = Dates::fromIsoTs('2025-03-01 14:30:00');
        $this->assertSame('2025-03-01 14:30:00', Dates::toIsoTs($d));
    }

    public function test_to_iso_ts_converts_timezone(): void
    {
        $d = new \DateTimeImmutable('2025-03-01 12:00:00', new \DateTimeZone('America/Sao_Paulo'));
        // -03:00 → 15:00:00 UTC
        $this->assertSame('2025-03-01 15:00:00', Dates::toIsoTs($d));
    }

    public function test_to_iso_ts_truncates_microseconds(): void
    {
        $d = new \DateTimeImmutable('2025-03-01 14:30:45.123456', new \DateTimeZone('UTC'));
        $this->assertSame('2025-03-01 14:30:45', Dates::toIsoTs($d));
    }

    #[DataProvider('impossibleDates')]
    public function test_impossible_dates_rejected(string $date): void
    {
        $this->expectException(DateException::class);
        Dates::parseDate($date);
    }

    public static function impossibleDates(): array
    {
        return [
            ['30/02/2025'],
            ['31/04/2025'],
            ['31/09/2025'],
            ['29/02/2025'],
            ['32/01/2025'],
            ['01/13/2025'],
            ['00/01/2025'],
            ['01/00/2025'],
        ];
    }
}
