<?php

namespace Tests\Unit;

use App\Services\MarketService;
use PHPUnit\Framework\TestCase;

class MarketServiceTest extends TestCase
{
    public function test_format_isk_small_value(): void
    {
        $this->assertEquals('123.45', MarketService::formatIsk(123.45));
    }

    public function test_format_isk_thousands(): void
    {
        $this->assertEquals('5.50K', MarketService::formatIsk(5500));
    }

    public function test_format_isk_millions(): void
    {
        $this->assertEquals('12.50M', MarketService::formatIsk(12500000));
    }

    public function test_format_isk_billions(): void
    {
        $this->assertEquals('3.20B', MarketService::formatIsk(3200000000));
    }

    public function test_format_isk_zero(): void
    {
        $this->assertEquals('0.00', MarketService::formatIsk(0));
    }

    public function test_format_isk_exact_thousand(): void
    {
        $this->assertEquals('1.00K', MarketService::formatIsk(1000));
    }

    public function test_format_isk_exact_million(): void
    {
        $this->assertEquals('1.00M', MarketService::formatIsk(1000000));
    }

    public function test_format_isk_exact_billion(): void
    {
        $this->assertEquals('1.00B', MarketService::formatIsk(1000000000));
    }

    public function test_format_isk_just_below_thousand(): void
    {
        $this->assertEquals('999.99', MarketService::formatIsk(999.99));
    }
}
