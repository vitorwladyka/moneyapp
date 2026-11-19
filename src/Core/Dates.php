<?php
declare(strict_types=1);

namespace Moneyapp\Core;

/**
 * Datas UTC, espelho de src/Core/Dates.php da spec v2.0 (§7).
 *
 * Princípios (DECISÃO FECHADA):
 *   - Toda data é DateTimeImmutable em UTC. Hora 00:00:00 para data de negócio.
 *   - Aplicação nunca lê o relógio diretamente — sempre Dates::nowUtc().
 *   - Formatação pt-BR (DD/MM/YYYY).
 *   - Parse estrito por regex ASCII ([0-9], \A..\z, sem /u, nunca \d) ANTES de
 *     construir o objeto. Depois valida calendário (checkdate).
 *   - Não usa createFromFormat (transborda datas e preenche campos omitidos com a hora atual).
 */
final class Dates
{
    private const BIZ_DATE_RE = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/';
    private const TS_RE = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/';
    private const PT_BR_DATE_RE = '/\A[0-9]{2}\/[0-9]{2}\/[0-9]{4}\z/';

    /** Agora, UTC, sem microssegundos. Único ponto que lê o relógio. */
    public static function nowUtc(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Trunca microssegundos.
        return $now->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            (int) $now->format('s'),
            0,
        );
    }

    /** 'YYYY-MM-DD HH:MM:SS'. Equivale a toIsoTs(nowUtc()). Sem offset. */
    public static function nowUtcIso(): string
    {
        return self::toIsoTs(self::nowUtc());
    }

    /**
     * Aceita APENAS 'DD/MM/YYYY' ou 'YYYY-MM-DD'.
     * Strip ASCII whitespace nas pontas. Retorna DateTimeImmutable UTC 00:00:00.
     * Rejeita não-string, outros formatos, datas impossíveis, ano com 2 dígitos.
     */
    public static function parseDate(mixed $text): \DateTimeImmutable
    {
        if (!\is_string($text)) {
            throw new DateException('parseDate exige string');
        }
        $stripped = \preg_replace('/^[\x20\x09\x0A\x0D\x0B]+|[\x20\x09\x0A\x0D\x0B]+$/u', '', $text) ?? '';
        if (\preg_match(self::PT_BR_DATE_RE, $stripped) === 1) {
            [$dd, $mm, $yyyy] = \explode('/', $stripped);
            return self::buildUtc((int) $yyyy, (int) $mm, (int) $dd, 0, 0, 0);
        }
        if (\preg_match(self::BIZ_DATE_RE, $stripped) === 1) {
            [$yyyy, $mm, $dd] = \explode('-', $stripped);
            return self::buildUtc((int) $yyyy, (int) $mm, (int) $dd, 0, 0, 0);
        }
        throw new DateException("Data inválida: '{$text}'");
    }

    /** '01/03/2025'. Sempre 4 dígitos de ano. Rejeita hora != 00:00:00. */
    public static function formatDate(mixed $d): string
    {
        self::assertBusinessDate($d);
        /** @var \DateTimeImmutable $d */
        return $d->format('d/m/Y');
    }

    /** Data de negócio -> 'YYYY-MM-DD'. */
    public static function toIso(mixed $d): string
    {
        self::assertBusinessDate($d);
        /** @var \DateTimeImmutable $d */
        return $d->format('Y-m-d');
    }

    /**
     * Timestamp técnico -> 'YYYY-MM-DD HH:MM:SS' UTC (sem microssegundos, sem offset).
     * Se o objeto estiver em outro fuso, converte para UTC antes de formatar.
     */
    public static function toIsoTs(mixed $d): string
    {
        if (!($d instanceof \DateTimeImmutable)) {
            throw new DateException('toIsoTs: DateTimeImmutable esperado');
        }
        $utc = $d->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format('Y-m-d H:i:s');
    }

    /** '2025-03-01' -> DateTimeImmutable UTC 00:00:00. */
    public static function fromIso(mixed $s): \DateTimeImmutable
    {
        if (!\is_string($s)) {
            throw new DateException('fromIso exige string');
        }
        $stripped = \preg_replace('/^[\x20\x09\x0A\x0D\x0B]+|[\x20\x09\x0A\x0D\x0B]+$/u', '', $s) ?? '';
        if (\preg_match(self::BIZ_DATE_RE, $stripped) !== 1) {
            throw new DateException("fromIso: formato inválido: '{$s}'");
        }
        [$yyyy, $mm, $dd] = \explode('-', $stripped);
        return self::buildUtc((int) $yyyy, (int) $mm, (int) $dd, 0, 0, 0);
    }

    /** '2025-03-01 14:30:00' -> DateTimeImmutable UTC. */
    public static function fromIsoTs(mixed $s): \DateTimeImmutable
    {
        if (!\is_string($s)) {
            throw new DateException('fromIsoTs exige string');
        }
        $stripped = \preg_replace('/^[\x20\x09\x0A\x0D\x0B]+|[\x20\x09\x0A\x0D\x0B]+$/u', '', $s) ?? '';
        if (\preg_match(self::TS_RE, $stripped) !== 1) {
            throw new DateException("fromIsoTs: formato inválido: '{$s}'");
        }
        [$datePart, $timePart] = \explode(' ', $stripped);
        [$yyyy, $mm, $dd] = \explode('-', $datePart);
        [$hh, $mi, $ss] = \explode(':', $timePart);
        return self::buildUtc((int) $yyyy, (int) $mm, (int) $dd, (int) $hh, (int) $mi, (int) $ss);
    }

    /**
     * Constrói DateTimeImmutable em UTC e valida ida e volta (rejeita 2025-02-30).
     */
    private static function buildUtc(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): \DateTimeImmutable {
        if ($month < 1 || $month > 12) {
            throw new DateException("Mês inválido: {$month}");
        }
        if ($day < 1 || $day > 31) {
            throw new DateException("Dia inválido: {$day}");
        }
        if ($hour < 0 || $hour > 23) {
            throw new DateException("Hora inválida: {$hour}");
        }
        if ($minute < 0 || $minute > 59) {
            throw new DateException("Minuto inválido: {$minute}");
        }
        if ($second < 0 || $second > 59) {
            throw new DateException("Segundo inválido: {$second}");
        }
        if (!\checkdate($month, $day, $year)) {
            throw new DateException("Data impossível: {$year}-{$month}-{$day}");
        }
        $str = \sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        $d = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $str,
            new \DateTimeZone('UTC'),
        );
        if ($d === false) {
            throw new DateException("Falha ao construir data: {$str}");
        }
        // Confirma ida e volta (defesa contra transbordamento silencioso).
        $roundtrip = $d->format('Y-m-d H:i:s');
        if ($roundtrip !== $str) {
            throw new DateException("Data não roundtrips: '{$str}' vs '{$roundtrip}'");
        }
        return $d;
    }

    private static function assertBusinessDate(mixed $d): void
    {
        if (!($d instanceof \DateTimeImmutable)) {
            throw new DateException('DateTimeImmutable esperado');
        }
        if ($d->format('H') !== '00' || $d->format('i') !== '00' || $d->format('s') !== '00') {
            throw new DateException('Data de negócio deve ter hora 00:00:00 UTC');
        }
        if ($d->getTimezone()->getName() !== 'UTC' && $d->getOffset() !== 0) {
            throw new DateException('Data de negócio deve estar em UTC');
        }
    }
}
