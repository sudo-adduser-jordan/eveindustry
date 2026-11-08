<?php

namespace Tests\Unit;

use App\Services\IndustryProfitCalculator;
use PHPUnit\Framework\TestCase;

class IndustryProfitCalculatorTest extends TestCase
{
    public function test_summary_applies_scc_fee_and_margin_on_input_cost(): void
    {
        // Output 2000, input 1000, job cost 50; SCC 4% of 2000 = 80
        // Profit = 2000 - 1000 - 50 - 80 = 870; margin = 870 / 1000 = 87%
        $summary = IndustryProfitCalculator::summary(2000.0, 1000.0, 50.0);

        $this->assertEqualsWithDelta(80.0, $summary['scc_fee'], 0.001);
        $this->assertEqualsWithDelta(870.0, $summary['profit'], 0.001);
        $this->assertEquals(87.0, $summary['profit_margin']);
    }

    public function test_summary_margin_is_zero_without_input_cost(): void
    {
        $summary = IndustryProfitCalculator::summary(1000.0, 0.0);

        $this->assertEquals(0.0, $summary['profit_margin']);
        $this->assertEqualsWithDelta(960.0, $summary['profit'], 0.001);
    }

    public function test_summary_margin_rounds_to_one_decimal(): void
    {
        // Profit = 1000 - 300 - 40 = 660; 660 / 300 = 220.0%
        $this->assertEquals(220.0, IndustryProfitCalculator::summary(1000.0, 300.0)['profit_margin']);
        // Profit = 100 - 70 - 4 = 26; 26 / 70 = 37.142…% → 37.1
        $this->assertEquals(37.1, IndustryProfitCalculator::summary(100.0, 70.0)['profit_margin']);
    }

    public function test_totals_give_profit_and_margin_on_input_cost_per_item_and_overall(): void
    {
        $result = IndustryProfitCalculator::totals([
            ['revenue' => 2000.0, 'input_cost' => 1000.0, 'fees' => 130.0],
            ['revenue' => 500.0, 'input_cost' => 600.0, 'fees' => 20.0],
        ]);

        // Item 1: 2000 - 1000 - 130 = 870 → 87%; item 2: 500 - 600 - 20 = -120 → -20%
        $this->assertEqualsWithDelta(870.0, $result['items'][0]['profit'], 0.001);
        $this->assertEquals(87.0, $result['items'][0]['profit_margin']);
        $this->assertEqualsWithDelta(-120.0, $result['items'][1]['profit'], 0.001);
        $this->assertEquals(-20.0, $result['items'][1]['profit_margin']);

        // Total profit 750 ÷ total input cost 1600 = 46.875% → 46.9
        // (the old JS divided by cost + fees: 750 / 1750 = 42.9%)
        $this->assertEqualsWithDelta(2500.0, $result['totals']['revenue'], 0.001);
        $this->assertEqualsWithDelta(1600.0, $result['totals']['input_cost'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['totals']['fees'], 0.001);
        $this->assertEqualsWithDelta(750.0, $result['totals']['profit'], 0.001);
        $this->assertEquals(46.9, $result['totals']['profit_margin']);
    }

    public function test_job_cost_charges_index_on_input_and_tax_on_job_fee(): void
    {
        // Input 10,000 at 5% index = 500 job fee; 10% facility tax on 500 = 50
        $cost = IndustryProfitCalculator::jobCost(10000.0, 5.0, 10.0);

        $this->assertEqualsWithDelta(500.0, $cost['job_fee'], 0.001);
        $this->assertEqualsWithDelta(50.0, $cost['facility_tax'], 0.001);
        $this->assertEqualsWithDelta(550.0, $cost['total_job_cost'], 0.001);
    }
}
