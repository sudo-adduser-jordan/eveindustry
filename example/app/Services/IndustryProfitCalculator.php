<?php

namespace App\Services;

/**
 * Profit and margin for industry jobs (manufacturing and reactions).
 *
 * Every calculator, browser, planner and precompute job goes through here so
 * the same item always shows the same profit and margin.
 */
class IndustryProfitCalculator
{
    // SCC (Secure Commerce Commission) fee: 4% of the output value
    public const SCC_FEE_RATE = 0.04;

    /**
     * Job fee and facility tax for a job, from the system cost index and
     * facility tax rate (both percentages). The job fee is charged on the
     * input cost and the facility tax on the job fee.
     *
     * @return array{job_fee: float, facility_tax: float, total_job_cost: float}
     */
    public static function jobCost(float $inputCost, float $systemCostIndexPercent, float $facilityTaxPercent): array
    {
        $jobFee = $inputCost * ($systemCostIndexPercent / 100);
        $facilityTax = $jobFee * ($facilityTaxPercent / 100);

        return [
            'job_fee' => $jobFee,
            'facility_tax' => $facilityTax,
            'total_job_cost' => $jobFee + $facilityTax,
        ];
    }

    /**
     * Profit after the SCC fee and any other costs, and margin as
     * profit ÷ input cost, as a percentage rounded to 0.1.
     *
     * @param  float  $otherCosts  Job fees and facility tax, if any
     * @return array{scc_fee: float, profit: float, profit_margin: float}
     */
    public static function summary(float $outputValue, float $inputCost, float $otherCosts = 0.0): array
    {
        $sccFee = $outputValue * self::SCC_FEE_RATE;
        $profit = $outputValue - $inputCost - $otherCosts - $sccFee;

        return [
            'scc_fee' => $sccFee,
            'profit' => $profit,
            'profit_margin' => self::margin($profit, $inputCost),
        ];
    }

    /**
     * Margin as profit ÷ input cost, as a percentage rounded to 0.1.
     */
    public static function margin(float $profit, float $inputCost): float
    {
        return $inputCost > 0 ? round($profit / $inputCost * 100, 1) : 0.0;
    }

    /**
     * Profit and margin for a list of jobs (e.g. the manufacturing build
     * queue), per job and in total.
     *
     * Each item has revenue, input_cost and fees, where fees are every fee
     * already worked out for that job (job fees, facility tax, SCC fee).
     * The total margin is total profit ÷ total input cost.
     *
     * @param  array<int, array{revenue: float, input_cost: float, fees: float}>  $items
     * @return array{items: array<int, array<string, float>>, totals: array<string, float>}
     */
    public static function totals(array $items): array
    {
        $rows = [];
        $totals = ['revenue' => 0.0, 'input_cost' => 0.0, 'fees' => 0.0, 'profit' => 0.0];

        foreach ($items as $item) {
            $revenue = (float) $item['revenue'];
            $inputCost = (float) $item['input_cost'];
            $fees = (float) $item['fees'];
            $profit = $revenue - $inputCost - $fees;

            $rows[] = [
                'revenue' => $revenue,
                'input_cost' => $inputCost,
                'fees' => $fees,
                'profit' => $profit,
                'profit_margin' => self::margin($profit, $inputCost),
            ];

            $totals['revenue'] += $revenue;
            $totals['input_cost'] += $inputCost;
            $totals['fees'] += $fees;
            $totals['profit'] += $profit;
        }

        $totals['profit_margin'] = self::margin($totals['profit'], $totals['input_cost']);

        return ['items' => $rows, 'totals' => $totals];
    }
}
