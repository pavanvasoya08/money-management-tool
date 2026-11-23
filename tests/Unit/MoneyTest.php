<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use App\Support\Percent;
use Tests\TestCase;

/** Exact integer money/percent maths — no floats in business logic. */
final class MoneyTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function testParsingIsExactAndStrict(): void
    {
        $this->assertSame(15000050, Money::parse('150000.5'));
        $this->assertSame(1, Money::parse('0.01'));
        $this->assertFalse(Money::isValidInput('1,000'), 'no grouping commas');
        $this->assertFalse(Money::isValidInput('10.999'), 'no silent rounding of a third decimal');
        $this->assertFalse(Money::isValidInput('0'), 'must be positive');
        $this->assertFalse(Money::isValidInput('-5'));
        $this->assertTrue(Money::isValidInput('9999999999999.99'));
    }

    public function testDatabaseRoundTripAndSumsAvoidFloatDrift(): void
    {
        $this->assertSame('0.30', Money::toDb(Money::fromDb('0.1') + Money::fromDb('0.2')));
        $this->assertSame(29, Money::fromDb(0.29), 'float from SQLite NUMERIC is rounded once to paise');
        $this->assertSame(Money::sum(array_fill(0, 1000, '0.10')), 10000);
        $this->assertSame('-12.05', Money::toDb(-1205));
    }

    public function testIndianFormatting(): void
    {
        $this->assertSame('₹12,34,56,789.00', Money::format(Money::parse('123456789')));
        $this->assertSame('₹999.50', Money::format(99950));
        $this->assertSame('-₹1,00,000.00', Money::format(-10000000));
    }

    public function testPercentages(): void
    {
        $this->assertSame(Percent::HUNDRED, Percent::parse('30') + Percent::parse('25') + Percent::parse('20') + Percent::parse('15') + Percent::parse('10'));
        $this->assertSame(Percent::HUNDRED, Percent::parse('33.3333') + Percent::parse('33.3333') + Percent::parse('33.3334'));
        $this->assertFalse(Percent::isValidInput('100.0001'));
        $this->assertSame('12.5%', Percent::format(Percent::fromDb('12.5000')));
    }
}
