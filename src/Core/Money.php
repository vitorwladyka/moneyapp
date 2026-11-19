<?php
declare(strict_types=1);

namespace Moneyapp\Core;

/**
 * Money imutável em centavos (int de 64 bits).
 *
 * Espelho de src/Core/Money.php da spec v2.0 (§6).
 * Princípios (DECISÃO FECHADA):
 *   - Centavos como int. Nunca float para aritmética de negócio.
 *   - 2 casas decimais (entrada pela UI; defesa em profundidade em parse/toStorage).
 *   - Arredondamento meia-unidade para longe de zero (ROUND_HALF_UP) olhando dígitos.
 *   - Teto técnico: |valor| < 9×10^15 centavos (< 2^53). Nenhum int estoura para float.
 *   - Limite individual persistido: MAX_ABS_VALUE = 999999999999.99.
 *   - Formatação pt-BR manual (R$ 1.181,47), sem NumberFormatter/setlocale.
 *   - Nenhuma função depende de configuração global (precision, bcscale, locale).
 */
final class Money
{
    public const MAX_ABS_VALUE = '999999999999.99'; // limite individual persistido

    /** Teto técnico: |valor| < 9×10^15 centavos (< 2^53). */
    private const MAX_ABS_CENTS = 9_000_000_000_000_000;

    /** Limite individual persistido em centavos. */
    private const MAX_ABS_INDIVIDUAL_CENTS = 99_999_999_999_999; // 999.999.999.999,99

    private function __construct(
        private readonly int $cents,
    ) {
    }

    /**
     * Constrói Money a partir de centavos int (validados).
     * Rejeita não-int, excedente do teto técnico.
     */
    public static function fromCents(int $cents): self
    {
        if (\abs($cents) >= self::MAX_ABS_CENTS) {
            throw new MoneyException("Valor excede o teto técnico: {$cents}");
        }
        return new self($cents);
    }

    /** Zero absoluto (sempre positivo). */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converte string decimal canônica ('1181.47', '-180.00', '0.00') em Money.
     * Arredonda para 2 casas, meia-unidade para longe de zero, sem usar float.
     * Rejeita formatos inválidos. Não verifica MAX_ABS_VALUE.
     */
    public static function fromDecimalString(mixed $value): self
    {
        if (!\is_string($value)) {
            throw new MoneyException('fromDecimalString exige string');
        }
        if (\preg_match('/\A-?[0-9]+(\.[0-9]+)?\z/', $value) !== 1) {
            throw new MoneyException("Decimal inválido: '{$value}'");
        }
        $negative = \str_starts_with($value, '-');
        $body = $negative ? \substr($value, 1) : $value;
        $parts = \explode('.', $body);
        $intPart = $parts[0] ?? '';
        $fracPart = $parts[1] ?? '';
        $fracPadded = \str_pad($fracPart, 3, '0'); // pega 3 casas para decidir arredondamento
        $intCents = ((int) $intPart) * 100;
        $fracCentsRaw = (int) \substr($fracPadded, 0, 2);
        $roundUp = (\strlen($fracPart) >= 3 && (int) $fracPadded[2] >= 5);
        $absCents = $intCents + $fracCentsRaw + ($roundUp ? 1 : 0);
        $cents = $negative ? -$absCents : $absCents;
        if (\abs($cents) >= self::MAX_ABS_CENTS) {
            throw new MoneyException("Valor excede o teto técnico: {$value}");
        }
        return new self($cents);
    }

    /**
     * Parse robusto de texto digitado pelo usuário (pt-BR ou en-US).
     * Implementa a gramática completa do §6:
     *   - Aceita '1.181,47', '1181,47', '1181.47', '1,234.56', 'R$ 1.181,47',
     *     '-R$ 10,00', '+R$ 10,00', '0,5', '1.000.000,00', '-0,00' -> 0.00.
     *   - Rejeita '1.234' e '1,234' (3 casas decimais em separador único).
     *   - Rejeita sinais duplicados, símbolo fora do lugar, espaços internos.
     *   - Rejeita |valor| > MAX_ABS_VALUE.
     */
    public static function parse(mixed $text): self
    {
        if (!\is_string($text)) {
            throw new MoneyException('parse exige string');
        }
        // Strip ASCII whitespace nas pontas (NÃO remove NBSP — Unicode U+00A0).
        $stripped = \preg_replace('/^[\x20\x09\x0A\x0D\x0B]+|[\x20\x09\x0A\x0D\x0B]+$/u', '', $text) ?? '';
        if ($stripped === '') {
            throw new MoneyException('Valor vazio');
        }

        // Símbolo de moeda opcional no início (R$ seguido de EXATAMENTE um espaço).
        // Padrão: sinais opcionais no início, depois 'R$ ' opcional, depois o número.
        if (\preg_match('/\A([+-]?)(R\$ )?(.*)\z/', $stripped, $m) !== 1) {
            throw new MoneyException("Formato inválido: '{$text}'");
        }
        $signStr = $m[1];
        $body = $m[3];

        // Símbolo depois do início é erro.
        if (\str_contains($body, 'R$')) {
            throw new MoneyException("Símbolo fora do lugar: '{$text}'");
        }
        // Espaço interno no corpo é erro.
        if (\preg_match('/[\x20\x09\x0A\x0D\x0B]/', $body) === 1) {
            throw new MoneyException("Espaço interno: '{$text}'");
        }
        // Só dígitos ASCII, ponto e vírgula.
        if (\preg_match('/\A[0-9.,]+\z/', $body) !== 1) {
            throw new MoneyException("Caracteres inválidos: '{$text}'");
        }

        $hasComma = \str_contains($body, ',');
        $hasDot = \str_contains($body, '.');

        if (!$hasComma && !$hasDot) {
            // Apenas dígitos.
            $cents = ((int) $body) * 100;
            return self::finalizeCents($signStr, $cents, $text);
        }

        // Determinar separador decimal e de milhar.
        if ($hasComma && $hasDot) {
            $lastComma = \strrpos($body, ',');
            $lastDot = \strrpos($body, '.');
            $decimalSep = $lastComma > $lastDot ? ',' : '.';
            $thousandsSep = $decimalSep === ',' ? '.' : ',';
        } elseif ($hasComma) {
            $decimalSep = ',';
            $thousandsSep = null;
        } else {
            $decimalSep = '.';
            $thousandsSep = null;
        }

        if ($thousandsSep === null) {
            // Separador único: pode aparecer UMA vez e é decimal.
            $parts = \explode($decimalSep, $body);
            if (\count($parts) !== 2) {
                throw new MoneyException("Separador repetido: '{$text}'");
            }
            [$intPart, $fracPart] = $parts;
            if ($intPart === '') {
                throw new MoneyException("Sem dígito inteiro: '{$text}'");
            }
            if ($fracPart === '') {
                throw new MoneyException("Sem dígito decimal: '{$text}'");
            }
            if (\strlen($fracPart) > 2) {
                throw new MoneyException("Mais de 2 casas decimais: '{$text}'");
            }
            if (\preg_match('/\A[0-9]+\z/', $intPart) !== 1 || \preg_match('/\A[0-9]+\z/', $fracPart) !== 1) {
                throw new MoneyException("Dígitos inválidos: '{$text}'");
            }
            // "02" → 2 centavos; "2" → 20 centavos (1 casa = décimos).
            // NÃO usar (int) em string com zero à esquerda + pad à direita de forma ambígua:
            // pad RIGHT em "2" → "20"; em "02" já tem 2 chars → "02".
            $fracCents = (int) \str_pad($fracPart, 2, '0', \STR_PAD_RIGHT);
            $cents = ((int) $intPart) * 100 + $fracCents;
            return self::finalizeCents($signStr, $cents, $text);
        }

        // Tem vírgula E ponto: validar agrupamento de milhar.
        $parts = \explode($decimalSep, $body);
        if (\count($parts) !== 2) {
            throw new MoneyException("Separador decimal repetido: '{$text}'");
        }
        [$intPart, $fracPart] = $parts;
        if ($fracPart === '' || \strlen($fracPart) > 2) {
            throw new MoneyException("Casas decimais inválidas: '{$text}'");
        }
        $groups = \explode($thousandsSep, $intPart);
        foreach ($groups as $i => $g) {
            if ($g === '') {
                throw new MoneyException("Grupo de milhar vazio: '{$text}'");
            }
            if ($i === 0) {
                if (\strlen($g) < 1 || \strlen($g) > 3) {
                    throw new MoneyException("Primeiro grupo de milhar inválido: '{$text}'");
                }
            } else {
                if (\strlen($g) !== 3) {
                    throw new MoneyException("Grupo de milhar inválido: '{$text}'");
                }
            }
        }
        $intStr = \implode('', $groups);
        if ($intStr === '') {
            throw new MoneyException("Sem dígito inteiro: '{$text}'");
        }
        if (\preg_match('/\A[0-9]+\z/', $fracPart) !== 1) {
            throw new MoneyException("Dígitos inválidos: '{$text}'");
        }
        $fracCents = (int) \str_pad($fracPart, 2, '0', \STR_PAD_RIGHT);
        $cents = ((int) $intStr) * 100 + $fracCents;
        return self::finalizeCents($signStr, $cents, $text);
    }

    /**
     * Converte valor lido do PDO em Money.
     * Aceita:
     *   - int:    reais inteiros (SQLite devolve int quando valor é inteiro).
     *   - float:  só valores vindos do SQLite (REAL/SUM). Rejeita NaN, INF, |f| >= 9×10^13.
     *             Convertido para centavos por arredondamento meia-unidade para longe de zero.
     *   - string: decimal como em fromDecimalString.
     * Rejeita null, bool, array, objeto.
     */
    public static function fromStorage(mixed $value): self
    {
        if ($value === null) {
            throw new MoneyException('fromStorage: valor nulo');
        }
        if (\is_bool($value)) {
            throw new MoneyException('fromStorage: bool não permitido');
        }
        if (\is_int($value)) {
            return self::fromCents($value * 100);
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new MoneyException("fromStorage: NaN/INF: {$value}");
            }
            if (\abs($value) >= 9e13) {
                throw new MoneyException("fromStorage: float fora do intervalo: {$value}");
            }
            $f100 = $value * 100;
            // Arredondamento meia-unidade para longe de zero.
            $rounded = $f100 >= 0 ? (int) \floor($f100 + 0.5) : (int) \ceil($f100 - 0.5);
            return self::fromCents($rounded);
        }
        if (\is_string($value)) {
            return self::fromDecimalString($value);
        }
        throw new MoneyException('fromStorage: tipo não suportado: ' . \gettype($value));
    }

    /**
     * Soma de Money. Iterável vazio -> 0.
     * Cada item deve ser Money. Respeita o teto técnico em cada passo.
     * Único ponto autorizado de soma monetária na aplicação (§3.9).
     */
    public static function sum(iterable $values): self
    {
        $acc = 0;
        foreach ($values as $v) {
            if (!($v instanceof self)) {
                throw new MoneyException('sum: item não é Money');
            }
            $acc += $v->cents;
            if (\abs($acc) >= self::MAX_ABS_CENTS) {
                throw new MoneyException("sum: estouro do teto técnico: {$acc}");
            }
        }
        return new self($acc);
    }

    /** Centavos como int. */
    public function toCents(): int
    {
        return $this->cents;
    }

    /** Decimal canônico com 2 casas: '1181.47', '-180.00', '0.00'. */
    public function toString(): string
    {
        $abs = \abs($this->cents);
        $intPart = (int) \floor($abs / 100);
        $fracPart = $abs % 100;
        $sign = $this->cents < 0 ? '-' : '';
        // STR_PAD_LEFT: 2 centavos → "0.02" (não "0.20").
        return $sign . $intPart . '.' . \str_pad((string) $fracPart, 2, '0', \STR_PAD_LEFT);
    }

    /**
     * String para bind em NUMERIC(14,2): toString() com verificação de MAX_ABS_VALUE.
     * Retorna string; o bind é feito como PDO::PARAM_STR pelo chamador.
     */
    public function toStorage(): string
    {
        if (\abs($this->cents) > self::MAX_ABS_INDIVIDUAL_CENTS) {
            throw new MoneyException('toStorage: valor excede MAX_ABS_VALUE: ' . $this->toString());
        }
        return $this->toString();
    }

    /**
     * pt-BR: 'R$ 1.181,47'. Negativo: '-R$ 180,00' (sinal antes do símbolo).
     * Com $withSymbol=false: '1.181,47' / '-180,00'. Zero é sempre positivo.
     */
    public function format(bool $withSymbol = true): string
    {
        $abs = \abs($this->cents);
        $intPart = (int) \floor($abs / 100);
        $fracPart = $abs % 100;
        // Agrupar milhar com ponto.
        $grouped = \preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $intPart) ?? (string) $intPart;
        $sign = $this->cents < 0 ? '-' : '';
        $symbol = $withSymbol ? 'R$ ' : '';
        // STR_PAD_LEFT: 2 centavos → "02" (não "20").
        return $sign . $symbol . $grouped . ',' . \str_pad((string) $fracPart, 2, '0', \STR_PAD_LEFT);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    private static function finalizeCents(string $sign, int $absCents, string $original): self
    {
        if (\abs($absCents) > self::MAX_ABS_INDIVIDUAL_CENTS) {
            throw new MoneyException("Valor excede MAX_ABS_VALUE: '{$original}'");
        }
        if (\abs($absCents) >= self::MAX_ABS_CENTS) {
            throw new MoneyException("Teto técnico: '{$original}'");
        }
        $signed = $sign === '-' ? -$absCents : $absCents;
        return new self($signed);
    }
}
