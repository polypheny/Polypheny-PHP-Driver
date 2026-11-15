<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;
use Stringable;

/**
 * An exact decimal value, represented as an unscaled integer and base-10 scale.
 */
final readonly class Decimal implements Stringable
{
    public string $unscaled;
    public int $scale;

    public function __construct(string $value)
    {
        if (preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Expected a finite decimal number.');
        }

        $fraction = ($parts[4] ?? '') === '' ? ($parts[3] ?? '') : $parts[4];
        $digits = ltrim(($parts[2] ?? '') . $fraction, '0');
        $this->unscaled = $digits === '' ? '0' : ($parts[1] === '-' ? '-' : '') . $digits;

        $exponent = $parts[5] ?? '0';
        $exponentDigits = ltrim($exponent, '+-0');
        if (strlen($exponentDigits) > 10) {
            throw new InvalidArgumentException('Decimal scale is outside the Prism signed 32-bit range.');
        }
        $exponentValue = (int) $exponentDigits * ($exponent[0] === '-' ? -1 : 1);
        $scale = strlen($fraction) - $exponentValue;
        if ($scale < -2_147_483_648 || $scale > 2_147_483_647) {
            throw new InvalidArgumentException('Decimal scale is outside the Prism signed 32-bit range.');
        }
        $this->scale = $scale;
    }

    public static function fromUnscaled(string $unscaled, int $scale): self
    {
        if (preg_match('/^[+-]?\d+$/D', $unscaled) !== 1 || $scale < -2_147_483_648 || $scale > 2_147_483_647) {
            throw new InvalidArgumentException('Expected an unscaled integer and a signed 32-bit scale.');
        }

        return new self($unscaled . 'E' . (-$scale));
    }

    public function __toString(): string
    {
        if ($this->scale === 0) {
            return $this->unscaled;
        }

        $sign = $this->unscaled[0] === '-' ? '-' : '';
        $digits = ltrim($this->unscaled, '-');
        $point = strlen($digits) - $this->scale;
        if ($this->scale > 0 && $point > 0) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if ($this->scale > 0 && $point >= -5) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }

        $fraction = substr($digits, 1);
        $mantissa = $digits[0] . ($fraction === '' ? '' : '.' . $fraction);

        return $sign . $mantissa . 'E' . sprintf('%+d', $point - 1);
    }
}
