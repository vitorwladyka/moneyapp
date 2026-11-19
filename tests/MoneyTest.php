<?php
declare(strict_types=1);

namespace Moneyapp\Tests;

use Moneyapp\Core\Money;
use Moneyapp\Core\MoneyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    public function test_zero_is_positive(): void
    {
        $z = Money::zero();
        $this->assertTrue($z->isZero());
        $this->assertFalse($z->isNegative());
        $this->assertSame('0.00', $z->toString());
        $this->assertSame('R$ 0,00', $z->format(true));
    }

    public function test_from_decimal_string_rounds_half_up(): void
    {
        $this->assertSame('1.18', Money::fromDecimalString('1.181')->toString());
        $this->assertSame('1.19', Money::fromDecimalString('1.185')->toString());
        $this->assertSame('-1.19', Money::fromDecimalString('-1.185')->toString());
        $this->assertSame('0.00', Money::fromDecimalString('-0.00')->toString());
    }

    public function test_from_decimal_string_rejects_invalid(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromDecimalString('1,181.47');
    }

    public function test_from_decimal_string_rejects_non_string(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromDecimalString(1181.47);
    }

    public static function parseAcceptedProvider(): array
    {
        return [
            ['1.181,47', '1181.47'],
            ['1181,47', '1181.47'],
            ['0,5', '0.50'],
            ['1181.47', '1181.47'],
            ['1,234.56', '1234.56'],
            ['1.000.000,00', '1000000.00'],
            ['R$ 1.181,47', '1181.47'],
            ['-180,00', '-180.00'],
            ['+50,00', '50.00'],
            ['-R$ 10,00', '-10.00'],
            ['+R$ 10,00', '10.00'],
            ['00050,00', '50.00'],
            ['-0,00', '0.00'],
            ['+0.00', '0.00'],
        ];
    }

    #[DataProvider('parseAcceptedProvider')]
    public function test_parse_accepts(string $input, string $expected): void
    {
        $m = Money::parse($input);
        $this->assertSame($expected, $m->toString());
    }

    public static function parseRejectedProvider(): array
    {
        return [
            [''],
            ['   '],
            ['1e3'],
            ['NaN'],
            ['Infinity'],
            ['--50'],
            ['+-50'],
            ['R$R$ 10'],
            ['10 R$'],
            ['R$1.181,47'],
            ['R$ -10,00'],
            ['- 50,00'],
            ['1 181,47'],
            [',50'],
            ['10,'],
            ['1.234'],
            ['1,234'],
            ['1.234.567'],
            ['12.34,56'],
            ['1.234,567.89'],
            ['2.7755575615628914e-17'],
        ];
    }

    #[DataProvider('parseRejectedProvider')]
    public function test_parse_rejects(string $input): void
    {
        $this->expectException(MoneyException::class);
        Money::parse($input);
    }

    public function test_parse_rejects_non_string(): void
    {
        $this->expectException(MoneyException::class);
        Money::parse(1181);
    }

    public function test_parse_rejects_above_max_individual(): void
    {
        $this->expectException(MoneyException::class);
        Money::parse('1.000.000.000.000,00');
    }

    public function test_format_pt_br(): void
    {
        $this->assertSame('R$ 1.181,47', Money::fromDecimalString('1181.47')->format(true));
        $this->assertSame('1.181,47', Money::fromDecimalString('1181.47')->format(false));
        $this->assertSame('-R$ 180,00', Money::fromDecimalString('-180')->format(true));
        $this->assertSame('R$ 1.000.000,00', Money::fromDecimalString('1000000')->format(true));
    }

    public function test_to_storage_validates_max(): void
    {
        $ok = Money::fromDecimalString('999999999999.99');
        $this->assertSame('999999999999.99', $ok->toStorage());

        $over = Money::fromCents(99_999_999_999_999 + 1);
        $this->expectException(MoneyException::class);
        $over->toStorage();
    }

    public function test_from_storage_int(): void
    {
        $m = Money::fromStorage(1181);
        $this->assertSame('1181.00', $m->toString());
    }

    public function test_from_storage_float_sum_residue(): void
    {
        // 0.10 + 0.20 - 0.30 em float devolve ~2.78e-17 — fromStorage zera.
        $residue = 0.10 + 0.20 - 0.30;
        $m = Money::fromStorage($residue);
        $this->assertTrue($m->isZero());
    }

    public function test_from_storage_string(): void
    {
        $this->assertSame('1181.47', Money::fromStorage('1181.47')->toString());
        $this->assertSame('-180.00', Money::fromStorage('-180.00')->toString());
    }

    public function test_from_storage_rejects_null(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromStorage(null);
    }

    public function test_from_storage_rejects_nan(): void
    {
        $this->expectException(MoneyException::class);
        Money::fromStorage(NAN);
    }

    public function test_sum_empty_is_zero(): void
    {
        $this->assertTrue(Money::sum([])->isZero());
    }

    public function test_sum_basic(): void
    {
        $items = [
            Money::fromDecimalString('10.00'),
            Money::fromDecimalString('20.00'),
            Money::fromDecimalString('-5.00'),
        ];
        $this->assertSame('25.00', Money::sum($items)->toString());
    }

    public function test_sum_rejects_non_money(): void
    {
        $this->expectException(MoneyException::class);
        /** @phpstan-ignore-next-line */
        Money::sum([Money::zero(), 100]);
    }

    public function test_equals(): void
    {
        $this->assertTrue(Money::fromDecimalString('10.00')->equals(Money::fromDecimalString('10.00')));
        $this->assertFalse(Money::fromDecimalString('10.00')->equals(Money::fromDecimalString('10.01')));
    }

    public function test_round_trip_storage(): void
    {
        $cases = ['0.00', '1181.47', '-180.00', '999999999999.99', '-999999999999.99'];
        foreach ($cases as $c) {
            $m = Money::fromDecimalString($c);
            $stored = $m->toStorage();
            $back = Money::fromStorage($stored);
            $this->assertTrue($m->equals($back), "Round trip falhou para {$c}");
        }
    }
}
