<?php
declare(strict_types=1);

namespace Moneyapp\Core;

/**
 * Exceção de Money. Toda rejeição de Money lança MoneyException
 * (estende \InvalidArgumentException para ser catch genérico).
 */
final class MoneyException extends \InvalidArgumentException
{
}
