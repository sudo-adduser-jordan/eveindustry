<?php

namespace App\Services\Calculators;

use App\Services\ManufacturingCalculatorService;

/**
 * Effective structure ME/TE bonuses from the dropdown settings on the
 * calculator pages (structure type, rig tier, security space).
 *
 * Copies of the private helpers in InventionPlannerController and the
 * public one in ManufacturingBrowserController, so the old and v1 routes
 * share one implementation through the services.
 */
class StructureBonuses
{
    /**
     * Engineering complex ME: role bonus and rig bonus combine
     * multiplicatively (each is an independent (1 - bonus/100) factor).
     */
    public static function manufacturingMe(string $structureType, string $rigMe, string $systemSecurity): float
    {
        $structureBonus = match ($structureType) {
            'raitaru', 'azbel', 'sotiyo' => ManufacturingCalculatorService::RAITARU_ME_BONUS,
            default => 0.0,
        };

        $rigBase = match ($rigMe) {
            't1' => ManufacturingCalculatorService::RIG_ME_T1,
            't2' => ManufacturingCalculatorService::RIG_ME_T2,
            default => 0.0,
        };

        return ManufacturingCalculatorService::combineMeBonuses(
            $structureBonus,
            $rigBase * self::securityMultiplier($systemSecurity)
        );
    }

    /**
     * Engineering complex TE: role bonus plus rig bonus × security multiplier.
     */
    public static function manufacturingTe(string $structureType, string $rigTe, string $systemSecurity): float
    {
        $structureBonus = match ($structureType) {
            'raitaru', 'azbel', 'sotiyo' => ManufacturingCalculatorService::RAITARU_TE_BONUS,
            default => 0.0,
        };

        $rigBase = match ($rigTe) {
            't1' => ManufacturingCalculatorService::RIG_TE_T1,
            't2' => ManufacturingCalculatorService::RIG_TE_T2,
            default => 0.0,
        };

        return $structureBonus + ($rigBase * self::securityMultiplier($systemSecurity));
    }

    /**
     * Refinery ME for reaction sub-components: rig bonus only (Athanor and
     * Tatara have no role ME bonus).
     */
    public static function reactionMe(string $rigMe, string $systemSecurity): float
    {
        $rigBase = match ($rigMe) {
            't1' => ManufacturingCalculatorService::RIG_ME_T1,
            't2' => ManufacturingCalculatorService::RIG_ME_T2,
            default => 0.0,
        };

        $securityMultiplier = match ($systemSecurity) {
            'highsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_HIGHSEC,
            'lowsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_LOWSEC,
            'nullsec' => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_NULLSEC,
            default => ManufacturingCalculatorService::REACTION_SECURITY_MULTIPLIER_HIGHSEC,
        };

        return $rigBase * $securityMultiplier;
    }

    /**
     * Manufacturing browser ME: rig bonus × security multiplier, no role
     * bonus.
     */
    public static function browserRigMe(string $rigTier = 'none', string $securitySpace = 'highsec'): float
    {
        $baseMe = match ($rigTier) {
            't1' => ManufacturingCalculatorService::RIG_ME_T1,
            't2' => ManufacturingCalculatorService::RIG_ME_T2,
            default => 0.0,
        };

        return $baseMe * self::securityMultiplier($securitySpace);
    }

    private static function securityMultiplier(string $systemSecurity): float
    {
        return match ($systemSecurity) {
            'lowsec' => ManufacturingCalculatorService::SECURITY_MULTIPLIER_LOWSEC,
            'nullsec' => ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC,
            default => ManufacturingCalculatorService::SECURITY_MULTIPLIER_HIGHSEC,
        };
    }
}
