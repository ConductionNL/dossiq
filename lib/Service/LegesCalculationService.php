<?php

/**
 * Procest Leges Calculation Service
 *
 * Rules engine for calculating municipal fees (leges) on permit cases.
 * Applies the gemeentelijke legesverordening to case attributes and produces
 * a calculated amount with breakdown per artikel and audit trail.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-leges-fees/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for calculating municipal fees (leges) on permit cases.
 *
 * Supports calculation types: vast bedrag (fixed), percentage, staffel (tiered),
 * maximum (capped), and combinatie (multiple types combined).
 *
 * @psalm-suppress UnusedClass
 */
class LegesCalculationService
{
    /**
     * Calculation type: fixed amount.
     */
    public const TYPE_VAST = 'vast';

    /**
     * Calculation type: percentage of a base amount.
     */
    public const TYPE_PERCENTAGE = 'percentage';

    /**
     * Calculation type: tiered brackets.
     */
    public const TYPE_STAFFEL = 'staffel';

    /**
     * Calculation type: capped maximum.
     */
    public const TYPE_MAXIMUM = 'maximum';

    /**
     * Calculation type: combination of multiple types.
     */
    public const TYPE_COMBINATIE = 'combinatie';

    /**
     * Calculation precision (decimal places).
     */
    private const PRECISION = 2;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Calculate leges for a case based on applicable verordening.
     *
     * @param array<string, mixed> $caseData     The case data (bouwkosten, activiteiten, etc.).
     * @param array<string, mixed> $verordening  The applicable verordening with artikelen.
     * @param string               $calculatedBy User ID of the person triggering the calculation.
     *
     * @return array{
     *     total: float,
     *     breakdown: array<int, array{artikel: string, description: string, grondslag: float, amount: float, type: string}>,
     *     verordening: string,
     *     calculatedBy: string,
     *     calculatedAt: string,
     *     version: int
     * }
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function calculate(
        array $caseData,
        array $verordening,
        string $calculatedBy,
    ): array {
        $this->logger->info(
            'Calculating leges for case with verordening {verordening}',
            ['verordening' => $verordening['name'] ?? 'unknown']
        );

        $artikelen = $verordening['artikelen'] ?? [];
        $breakdown = [];
        $total     = 0.0;

        foreach ($artikelen as $artikel) {
            $result = $this->calculateArtikel(artikel: $artikel, caseData: $caseData);
            if ($result !== null) {
                $breakdown[] = $result;
                $total      += $result['amount'];
            }
        }

        // Apply global maximum if configured.
        $globalMax = $verordening['globalMaximum'] ?? null;
        if ($globalMax !== null && $total > (float) $globalMax) {
            $total = (float) $globalMax;
        }

        $total = round($total, self::PRECISION);

        return [
            'total'        => $total,
            'breakdown'    => $breakdown,
            'verordening'  => $verordening['name'] ?? '',
            'calculatedBy' => $calculatedBy,
            'calculatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'version'      => 1,
        ];
    }//end calculate()

    /**
     * Recalculate leges with corrected case data, preserving history.
     *
     * @param array<string, mixed> $caseData         The corrected case data.
     * @param array<string, mixed> $verordening      The applicable verordening.
     * @param array<string, mixed> $previousCalc     The previous calculation result.
     * @param string               $calculatedBy     User ID.
     * @param string               $correctionReason Reason for the correction.
     *
     * @return array<string, mixed> The new calculation with version incremented.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function recalculate(
        array $caseData,
        array $verordening,
        array $previousCalc,
        string $calculatedBy,
        string $correctionReason,
    ): array {
        $newCalc            = $this->calculate(caseData: $caseData, verordening: $verordening, calculatedBy: $calculatedBy);
        $newCalc['version'] = ($previousCalc['version'] ?? 0) + 1;
        $newCalc['previousVersion']  = $previousCalc['version'] ?? 0;
        $newCalc['correctionReason'] = $correctionReason;
        $newCalc['previousTotal']    = $previousCalc['total'] ?? 0.0;
        $newCalc['difference']       = round(
            $newCalc['total'] - ($previousCalc['total'] ?? 0.0),
            self::PRECISION
        );

        return $newCalc;
    }//end recalculate()

    /**
     * Calculate verrekening (deduction of previously imposed fees).
     *
     * @param float $currentAmount  The current calculation amount.
     * @param float $previousAmount The previously imposed amount.
     *
     * @return array{netAmount: float, deduction: float, currentAmount: float, previousAmount: float}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function calculateVerrekening(float $currentAmount, float $previousAmount): array
    {
        $netAmount = round($currentAmount - $previousAmount, self::PRECISION);

        return [
            'netAmount'      => $netAmount,
            'deduction'      => $previousAmount,
            'currentAmount'  => $currentAmount,
            'previousAmount' => $previousAmount,
        ];
    }//end calculateVerrekening()

    /**
     * Calculate teruggaaf (refund).
     *
     * @param float  $imposedAmount  The originally imposed amount.
     * @param float  $refundFraction Fraction to refund (0.0 - 1.0, default 1.0 for full refund).
     * @param string $reason         Reason for the refund.
     *
     * @return array{refundAmount: float, originalAmount: float, fraction: float, reason: string}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function calculateTeruggaaf(
        float $imposedAmount,
        float $refundFraction=1.0,
        string $reason='',
    ): array {
        $refundAmount = round(-1 * $imposedAmount * $refundFraction, self::PRECISION);

        return [
            'refundAmount'   => $refundAmount,
            'originalAmount' => $imposedAmount,
            'fraction'       => $refundFraction,
            'reason'         => $reason,
        ];
    }//end calculateTeruggaaf()

    /**
     * Calculate a single artikel.
     *
     * @param array<string, mixed> $artikel  The artikel definition.
     * @param array<string, mixed> $caseData The case data.
     *
     * @return array{artikel: string, description: string, grondslag: float, amount: float, type: string}|null
     */
    private function calculateArtikel(array $artikel, array $caseData): ?array
    {
        $type        = $artikel['type'] ?? '';
        $artikelNr   = $artikel['nummer'] ?? '';
        $description = $artikel['omschrijving'] ?? '';

        // Determine the grondslag (base amount) from case data.
        $grondslagField = $artikel['grondslagField'] ?? 'bouwkosten';
        $grondslag      = (float) ($caseData[$grondslagField] ?? 0.0);

        $amount = match ($type) {
            self::TYPE_VAST => $this->calculateVast(artikel: $artikel),
            self::TYPE_PERCENTAGE => $this->calculatePercentage(grondslag: $grondslag, artikel: $artikel),
            self::TYPE_STAFFEL => $this->calculateStaffel(grondslag: $grondslag, artikel: $artikel),
            self::TYPE_MAXIMUM => $this->calculateMaximum(grondslag: $grondslag, artikel: $artikel),
            self::TYPE_COMBINATIE => $this->calculateCombinatie(grondslag: $grondslag, artikel: $artikel, caseData: $caseData),
            default => null,
        };

        if ($amount === null) {
            return null;
        }

        return [
            'artikel'     => $artikelNr,
            'description' => $description,
            'grondslag'   => $grondslag,
            'amount'      => round($amount, self::PRECISION),
            'type'        => $type,
        ];
    }//end calculateArtikel()

    /**
     * Calculate a fixed amount (vast bedrag).
     *
     * @param array<string, mixed> $artikel The artikel definition.
     *
     * @return float The fixed amount.
     */
    private function calculateVast(array $artikel): float
    {
        return (float) ($artikel['bedrag'] ?? 0.0);
    }//end calculateVast()

    /**
     * Calculate a percentage of the grondslag.
     *
     * @param float                $grondslag The base amount.
     * @param array<string, mixed> $artikel   The artikel definition.
     *
     * @return float The calculated amount.
     */
    private function calculatePercentage(float $grondslag, array $artikel): float
    {
        $percentage = (float) ($artikel['percentage'] ?? 0.0);
        return $grondslag * ($percentage / 100.0);
    }//end calculatePercentage()

    /**
     * Calculate using tiered brackets (staffel).
     *
     * Each bracket has a 'from', 'to', and 'percentage'.
     * The amount within each bracket is multiplied by the bracket's rate.
     *
     * @param float                $grondslag The base amount.
     * @param array<string, mixed> $artikel   The artikel with 'brackets' array.
     *
     * @return float The total calculated across all brackets.
     */
    private function calculateStaffel(float $grondslag, array $artikel): float
    {
        $brackets = $artikel['brackets'] ?? [];
        $total    = 0.0;

        foreach ($brackets as $bracket) {
            $from       = (float) ($bracket['from'] ?? 0.0);
            $to         = (float) ($bracket['to'] ?? PHP_FLOAT_MAX);
            $percentage = (float) ($bracket['percentage'] ?? 0.0);

            if ($grondslag <= $from) {
                break;
            }

            $bracketAmount = min($grondslag, $to) - $from;
            if ($bracketAmount > 0) {
                $total += $bracketAmount * ($percentage / 100.0);
            }
        }

        return $total;
    }//end calculateStaffel()

    /**
     * Calculate with a maximum cap.
     *
     * @param float                $grondslag The base amount.
     * @param array<string, mixed> $artikel   The artikel with 'maximum' and calculation sub-type.
     *
     * @return float The capped amount.
     */
    private function calculateMaximum(float $grondslag, array $artikel): float
    {
        $maximum = (float) ($artikel['maximum'] ?? PHP_FLOAT_MAX);
        $subType = $artikel['subType'] ?? self::TYPE_PERCENTAGE;

        $calculated = match ($subType) {
            self::TYPE_PERCENTAGE => $this->calculatePercentage(grondslag: $grondslag, artikel: $artikel),
            self::TYPE_STAFFEL => $this->calculateStaffel(grondslag: $grondslag, artikel: $artikel),
            default => $this->calculateVast(artikel: $artikel),
        };

        return min($calculated, $maximum);
    }//end calculateMaximum()

    /**
     * Calculate a combination of multiple sub-calculations.
     *
     * @param float                $grondslag The base amount.
     * @param array<string, mixed> $artikel   The artikel with 'subArtikelen'.
     * @param array<string, mixed> $caseData  The case data.
     *
     * @return float The combined total.
     */
    private function calculateCombinatie(
        float $grondslag,
        array $artikel,
        array $caseData,
    ): float {
        $subArtikelen = $artikel['subArtikelen'] ?? [];
        $total        = 0.0;

        foreach ($subArtikelen as $subArtikel) {
            $result = $this->calculateArtikel(artikel: $subArtikel, caseData: $caseData);
            if ($result !== null) {
                $total += $result['amount'];
            }
        }

        return $total;
    }//end calculateCombinatie()
}//end class
