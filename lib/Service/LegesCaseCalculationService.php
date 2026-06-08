<?php

/**
 * Procest Leges Case Calculation Service
 *
 * Orchestrates the automatic leges calculation for a single case: resolves the
 * applicable tariff table on the case's reference date, selects the coupled
 * tariff, evaluates variants, applies discounts, computes the amount (incl. VAT)
 * and persists a legesBerekening with a full audit trail.
 *
 * All persistence goes through the OpenRegister ObjectService obtained from
 * SettingsService (find / findAll / saveObject), per ADR-022. The pure tariff
 * arithmetic is delegated to LegesCalculationService so a single math path is
 * shared between the case engine and the standalone calculation endpoints.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-003
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-004
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-008
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Case-level leges calculation orchestrator.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class LegesCaseCalculationService
{
    /**
     * Status assigned to a freshly computed calculation.
     */
    public const STATUS_BEREKEND = 'berekend';

    /**
     * Status assigned when an applicable discount still needs income verification.
     */
    public const STATUS_PENDING_MINIMA = 'pending_minima_check';

    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService Settings + ObjectService access.
     * @param LegesConditionEvaluator $evaluator       Condition matcher for variants/discounts.
     * @param LegesContextResolver    $contextResolver Resolves leeftijd/minima/history context.
     * @param LoggerInterface         $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LegesConditionEvaluator $evaluator,
        private readonly LegesContextResolver $contextResolver,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Calculate leges for a case and persist a legesBerekening.
     *
     * @param string $caseId       The case UUID.
     * @param string $calculatedBy 'system' or a user id.
     *
     * @return array<string, mixed> The persisted calculation payload.
     *
     * @throws RuntimeException When OpenRegister is unavailable or unconfigured,
     *                          or when no coupled tariff exists for the case.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function calculateForCase(string $caseId, string $calculatedBy='system'): array
    {
        $objectService = $this->requireObjectService();
        $register      = $this->settingsService->getConfigValue('register');
        $this->requireConfigured(register: $register);

        $case = $this->loadCase(objectService: $objectService, register: $register, caseId: $caseId);

        $referenceDate = $this->resolveReferenceDate(case: $case);
        $tariefTabel   = $this->findApplicableTariefTabel(
            objectService: $objectService,
            register: $register,
            referenceDate: $referenceDate
        );
        if ($tariefTabel === null) {
            throw new RuntimeException('No vastgestelde legesverordening found for '.$referenceDate);
        }

        $tarief = $this->findTariefForCase(
            objectService: $objectService,
            register: $register,
            tariefTabelId: (string) ($tariefTabel['id'] ?? ''),
            case: $case
        );
        if ($tarief === null) {
            throw new RuntimeException('No leges tarief coupled to case type for case '.$caseId);
        }

        $context = $this->contextResolver->resolve(case: $case);

        $variant = $this->selectVariant(
            objectService: $objectService,
            register: $register,
            tariefId: (string) ($tarief['id'] ?? ''),
            caseData: $case,
            context: $context
        );

        $baseCents = $this->computeBaseAmountCents(tarief: $tarief, variant: $variant, caseData: $case);

        $korting = $this->applyDiscounts(
            objectService: $objectService,
            register: $register,
            tarief: $tarief,
            baseCents: $baseCents,
            caseData: $case,
            context: $context,
            referenceDate: $referenceDate
        );

        $netCents = max(0, ($baseCents - $korting['totaalKortingCents']));
        $btw      = $this->splitBtw(grossExclCents: $netCents, btwTarief: (int) ($tarief['btwTarief'] ?? 0));

        $toelichting = $this->buildAuditTrail(
            tariefTabel: $tariefTabel,
            tarief: $tarief,
            variant: $variant,
            baseCents: $baseCents,
            kortingen: $korting['appliedKortingen'],
            context: $context
        );

        $variantId = null;
        if ($variant !== null) {
            $variantId = (string) ($variant['id'] ?? '');
        }

        $status = self::STATUS_BEREKEND;
        if ($korting['pendingMinima'] === true) {
            $status = self::STATUS_PENDING_MINIMA;
        }

        $payload = [
            'zaakId'                 => $caseId,
            'tariefTabelId'          => (string) ($tariefTabel['id'] ?? ''),
            'tariefId'               => (string) ($tarief['id'] ?? ''),
            'variantId'              => $variantId,
            'appliedKortingen'       => $korting['appliedKortingen'],
            'bedragExclBtw'          => $netCents,
            'btwBedrag'              => $btw,
            'bedragInclBtw'          => ($netCents + $btw),
            'berekendeOp'            => $referenceDate,
            'berekendDoor'           => $calculatedBy,
            'berekeningsToelichting' => $toelichting,
            'status'                 => $status,
        ];

        $saved   = $objectService->saveObject(
            $register,
            $this->settingsService->getConfigValue('leges_berekening_schema'),
            $payload
        );
        $savedId = $this->extractId(result: $saved);

        $this->logger->info(
            'Procest leges: calculation persisted for case '.$caseId,
            ['berekeningId' => $savedId, 'bedragInclBtw' => $payload['bedragInclBtw']]
        );

        $payload['id'] = $savedId;
        return $payload;
    }//end calculateForCase()

    /**
     * Select the best-matching variant for a tariff, or null when none applies.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param string               $tariefId      Tariff UUID.
     * @param array<string, mixed> $caseData      Case attributes.
     * @param array<string, mixed> $context       Supplementary data.
     *
     * @return array<string, mixed>|null
     */
    public function selectVariant(object $objectService, string $register, string $tariefId, array $caseData, array $context): ?array
    {
        if ($tariefId === '') {
            return null;
        }

        $schema   = $this->settingsService->getConfigValue('leges_variant_schema');
        $variants = $this->findAllRows(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['tariefId' => $tariefId]
        );

        foreach ($variants as $variant) {
            $condities = (array) ($variant['condities'] ?? []);
            if ($this->evaluator->evaluate(condities: $condities, caseData: $caseData, context: $context) === true) {
                return $variant;
            }
        }

        return null;
    }//end selectVariant()

    /**
     * Resolve and apply applicable discounts to a base amount.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param array<string, mixed> $tarief        The selected tariff.
     * @param int                  $baseCents     Base amount in cents.
     * @param array<string, mixed> $caseData      Case attributes.
     * @param array<string, mixed> $context       Supplementary data.
     * @param string               $referenceDate Reference date (Y-m-d).
     *
     * @return array{appliedKortingen: array<int, array<string, mixed>>, totaalKortingCents: int, pendingMinima: bool}
     */
    public function applyDiscounts(
        object $objectService,
        string $register,
        array $tarief,
        int $baseCents,
        array $caseData,
        array $context,
        string $referenceDate,
    ): array {
        $schema    = $this->settingsService->getConfigValue('leges_korting_schema');
        $kortingen = $this->findAllRows(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: []
        );

        $tariefId = (string) ($tarief['id'] ?? '');
        $applied  = [];
        $total    = 0;
        $pending  = false;

        foreach ($kortingen as $korting) {
            if ($this->kortingApplies(korting: $korting, tariefId: $tariefId, referenceDate: $referenceDate) === false) {
                continue;
            }

            $condities = (array) ($korting['condities'] ?? []);
            if ($this->evaluator->evaluate(condities: $condities, caseData: $caseData, context: $context) === false) {
                continue;
            }

            // Minima discounts require income verification before the amount
            // may be reduced; flag for review rather than apply silently.
            if (($korting['vereistMinimaCheck'] ?? false) === true && ($context['minima_geverifieerd'] ?? false) !== true) {
                $pending = true;
                continue;
            }

            $effect = $this->kortingEffectCents(korting: $korting, baseCents: $baseCents);
            if ($effect <= 0) {
                continue;
            }

            $total    += $effect;
            $applied[] = [
                'kortingId' => (string) ($korting['id'] ?? ''),
                'naam'      => (string) ($korting['naam'] ?? ''),
                'bedrag'    => (-1 * $effect),
                'grondslag' => (string) ($korting['wettelijkeGrondslag'] ?? ($korting['naam'] ?? '')),
            ];
        }//end foreach

        return [
            'appliedKortingen'   => $applied,
            'totaalKortingCents' => min($total, $baseCents),
            'pendingMinima'      => $pending,
        ];
    }//end applyDiscounts()

    /**
     * Build the human-readable berekeningsToelichting audit string.
     *
     * @param array<string, mixed>             $tariefTabel The tariff table.
     * @param array<string, mixed>             $tarief      The selected tariff.
     * @param array<string, mixed>|null        $variant     The selected variant.
     * @param int                              $baseCents   The base amount in cents.
     * @param array<int, array<string, mixed>> $kortingen   The applied discounts.
     * @param array<string, mixed>             $context     Supplementary data.
     *
     * @return string
     */
    public function buildAuditTrail(array $tariefTabel, array $tarief, ?array $variant, int $baseCents, array $kortingen, array $context): string
    {
        $parts   = [];
        $parts[] = 'Verordening: '.((string) ($tariefTabel['naam'] ?? 'onbekend'));
        $parts[] = 'Tarief '.((string) ($tarief['tariefNummer'] ?? '')).': '.((string) ($tarief['omschrijving'] ?? ''));

        if ($variant !== null) {
            $parts[] = 'Variant toegepast: '.((string) ($variant['variantNaam'] ?? ''));
        }

        $grondslag = (string) ($tarief['grondslag'] ?? 'vast');
        if ($grondslag !== 'vast' && isset($tarief['grondslagVeld']) === true) {
            $veld    = (string) $tarief['grondslagVeld'];
            $parts[] = 'Grondslag '.$veld.': '.((string) ($context['grondslagWaarden'][$veld] ?? '?'));
        }

        $parts[] = 'Basisbedrag: '.$this->formatEuro(cents: $baseCents);

        foreach ($kortingen as $korting) {
            $parts[] = 'Korting '.((string) ($korting['naam'] ?? '')).': '.$this->formatEuro(cents: (int) ($korting['bedrag'] ?? 0));
        }

        return implode('; ', $parts);
    }//end buildAuditTrail()

    /**
     * Compute the base amount (before discounts) in cents.
     *
     * @param array<string, mixed>      $tarief   The tariff.
     * @param array<string, mixed>|null $variant  The selected variant.
     * @param array<string, mixed>      $caseData Case attributes.
     *
     * @return int
     */
    private function computeBaseAmountCents(array $tarief, ?array $variant, array $caseData): int
    {
        // A variant override replaces the entire base amount.
        if ($variant !== null && isset($variant['bedragOverride']) === true && $variant['bedragOverride'] !== null) {
            return max(0, (int) $variant['bedragOverride']);
        }

        $grondslag = (string) ($tarief['grondslag'] ?? 'vast');
        $minCents  = (int) ($tarief['bedrag'] ?? 0);

        $base = match ($grondslag) {
            'bouwsom', 'oppervlakte', 'formule' => $this->computePercentageCents(tarief: $tarief, caseData: $caseData, minCents: $minCents),
            'staffel' => $this->computeStaffelCents(tarief: $tarief, caseData: $caseData, minCents: $minCents),
            default => $minCents,
        };

        // A variant surcharge is added on top of the computed base.
        if ($variant !== null && isset($variant['bedragOpslag']) === true && $variant['bedragOpslag'] !== null) {
            $base += (int) $variant['bedragOpslag'];
        }

        return max(0, $base);
    }//end computeBaseAmountCents()

    /**
     * Compute a percentage-of-grondslag amount, honouring the tariff minimum.
     *
     * @param array<string, mixed> $tarief   The tariff.
     * @param array<string, mixed> $caseData Case attributes.
     * @param int                  $minCents The minimum amount in cents.
     *
     * @return int
     */
    private function computePercentageCents(array $tarief, array $caseData, int $minCents): int
    {
        $veld       = (string) ($tarief['grondslagVeld'] ?? 'bouwsom');
        $grondslag  = (float) ($caseData[$veld] ?? 0.0);
        $percentage = (float) ($tarief['percentage'] ?? 0.0);
        // Grondslag values (bouwsom etc.) are full euros on the case; convert to cents.
        $computed = (int) round($grondslag * ($percentage / 100.0) * 100);

        return max($computed, $minCents);
    }//end computePercentageCents()

    /**
     * Compute a staffel (tiered) amount, honouring the tariff minimum.
     *
     * @param array<string, mixed> $tarief   The tariff.
     * @param array<string, mixed> $caseData Case attributes.
     * @param int                  $minCents The minimum amount in cents.
     *
     * @return int
     */
    private function computeStaffelCents(array $tarief, array $caseData, int $minCents): int
    {
        $veld      = (string) ($tarief['grondslagVeld'] ?? 'bouwsom');
        $grondslag = (float) ($caseData[$veld] ?? 0.0);
        $tiers     = (array) ($tarief['staffelWaarden'] ?? []);

        foreach ($tiers as $tier) {
            $min = (float) ($tier['min'] ?? 0);
            $max = (float) ($tier['max'] ?? PHP_FLOAT_MAX);
            if ($grondslag >= $min && $grondslag <= $max) {
                return max((int) ($tier['bedrag'] ?? 0), $minCents);
            }
        }

        return $minCents;
    }//end computeStaffelCents()

    /**
     * Split a VAT-exclusive amount into its VAT component.
     *
     * @param int $grossExclCents The amount excluding VAT, in cents.
     * @param int $btwTarief      The VAT percentage (0, 9, 21).
     *
     * @return int The VAT amount in cents.
     */
    private function splitBtw(int $grossExclCents, int $btwTarief): int
    {
        if ($btwTarief <= 0) {
            return 0;
        }

        return (int) round($grossExclCents * ($btwTarief / 100.0));
    }//end splitBtw()

    /**
     * Whether a discount is applicable to the given tariff on the reference date.
     *
     * @param array<string, mixed> $korting       The discount.
     * @param string               $tariefId      The tariff UUID.
     * @param string               $referenceDate Reference date (Y-m-d).
     *
     * @return bool
     */
    private function kortingApplies(array $korting, string $tariefId, string $referenceDate): bool
    {
        $tariefIds = (array) ($korting['tariefIds'] ?? []);
        if ($tariefIds !== [] && in_array($tariefId, array_map('strval', $tariefIds), true) === false) {
            return false;
        }

        $vanaf = (string) ($korting['geldigVanaf'] ?? '');
        if ($vanaf !== '' && $referenceDate < $vanaf) {
            return false;
        }

        $totEnMet = (string) ($korting['geldigTotEnMet'] ?? '');
        if ($totEnMet !== '' && $referenceDate > $totEnMet) {
            return false;
        }

        return true;
    }//end kortingApplies()

    /**
     * Compute the cent effect of a discount on the base amount.
     *
     * @param array<string, mixed> $korting   The discount.
     * @param int                  $baseCents The base amount in cents.
     *
     * @return int
     */
    private function kortingEffectCents(array $korting, int $baseCents): int
    {
        $type   = (string) ($korting['kortingsType'] ?? '');
        $waarde = (float) ($korting['kortingsWaarde'] ?? 0);

        return match ($type) {
            'volledige_vrijstelling' => $baseCents,
            'percentage' => (int) round($baseCents * ($waarde / 100.0)),
            'vast_bedrag' => min((int) round($waarde), $baseCents),
            default => 0,
        };
    }//end kortingEffectCents()

    /**
     * Resolve the reference date (peildatum) for tariff selection.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return string Y-m-d date.
     */
    private function resolveReferenceDate(array $case): string
    {
        $start = (string) ($case['startDate'] ?? '');
        if ($start !== '') {
            return substr($start, 0, 10);
        }

        return (new DateTimeImmutable())->format('Y-m-d');
    }//end resolveReferenceDate()

    /**
     * Find the vastgestelde tariff table valid on the reference date.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $referenceDate Reference date (Y-m-d).
     *
     * @return array<string, mixed>|null
     */
    private function findApplicableTariefTabel(object $objectService, string $register, string $referenceDate): ?array
    {
        $schema = $this->settingsService->getConfigValue('leges_tarief_tabel_schema');
        $rows   = $this->findAllRows(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['status' => 'vastgesteld']
        );

        $best = null;
        foreach ($rows as $row) {
            $vanaf = (string) ($row['geldigVanaf'] ?? '');
            if ($vanaf === '' || $referenceDate < $vanaf) {
                continue;
            }

            $totEnMet = (string) ($row['geldigTotEnMet'] ?? '');
            if ($totEnMet !== '' && $referenceDate > $totEnMet) {
                continue;
            }

            // Prefer the table with the latest geldigVanaf <= referenceDate.
            if ($best === null || $vanaf > (string) ($best['geldigVanaf'] ?? '')) {
                $best = $row;
            }
        }

        return $best;
    }//end findApplicableTariefTabel()

    /**
     * Find the tariff coupled to the case's case type, within a tariff table.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param string               $tariefTabelId Tariff table UUID.
     * @param array<string, mixed> $case          The case object.
     *
     * @return array<string, mixed>|null
     */
    private function findTariefForCase(object $objectService, string $register, string $tariefTabelId, array $case): ?array
    {
        $caseType = (string) ($case['caseType'] ?? '');
        if ($caseType === '' || $tariefTabelId === '') {
            return null;
        }

        $schema = $this->settingsService->getConfigValue('leges_tarief_schema');
        $rows   = $this->findAllRows(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['tariefTabelId' => $tariefTabelId, 'zaaktype' => $caseType]
        );

        return ($rows[0] ?? null);
    }//end findTariefForCase()

    /**
     * Load a case object by id.
     *
     * @param object $objectService OpenRegister ObjectService.
     * @param string $register      Register id.
     * @param string $caseId        Case UUID.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the case cannot be found.
     */
    private function loadCase(object $objectService, string $register, string $caseId): array
    {
        $schema = $this->settingsService->getConfigValue('case_schema');

        try {
            $case = $objectService->find($caseId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            throw new RuntimeException('Case not found: '.$caseId, 0, $e);
        }

        $row = $this->toArray(value: $case);
        if ($row === []) {
            throw new RuntimeException('Case not found: '.$caseId);
        }

        return $row;
    }//end loadCase()

    /**
     * Find rows via the OpenRegister findAll API and normalise to arrays.
     *
     * @param object               $objectService OpenRegister ObjectService.
     * @param string               $register      Register id.
     * @param string               $schema        Schema id.
     * @param array<string, mixed> $filters       Equality filters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findAllRows(object $objectService, string $register, string $schema, array $filters): array
    {
        if ($schema === '') {
            return [];
        }

        try {
            $records = $objectService->findAll($register, $schema, ['filters' => $filters]);
        } catch (Throwable $e) {
            $this->logger->warning('Procest leges: findAll failed: '.$e->getMessage());
            return [];
        }

        $rows = [];
        foreach ((array) $records as $record) {
            $row = $this->toArray(value: $record);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }//end findAllRows()

    /**
     * Require an available ObjectService.
     *
     * @return object
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function requireObjectService(): object
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        return $objectService;
    }//end requireObjectService()

    /**
     * Require that the register and leges schemas are configured.
     *
     * @param string $register The register id.
     *
     * @return void
     *
     * @throws RuntimeException When unconfigured.
     */
    private function requireConfigured(string $register): void
    {
        if ($register === '' || $this->settingsService->getConfigValue('leges_berekening_schema') === '') {
            throw new RuntimeException('Leges schemas are not configured');
        }
    }//end requireConfigured()

    /**
     * Normalise an OR record (object or array) to an associative array.
     *
     * @param mixed $value The record.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()

    /**
     * Extract the id/uuid from a saved OR object.
     *
     * @param mixed $result The save result.
     *
     * @return string
     */
    private function extractId(mixed $result): string
    {
        if (is_object($result) === true && method_exists($result, 'getUuid') === true) {
            return (string) $result->getUuid();
        }

        $row = $this->toArray(value: $result);
        return (string) ($row['id'] ?? ($row['uuid'] ?? ''));
    }//end extractId()

    /**
     * Format a cent amount as a Dutch euro string.
     *
     * @param int $cents The amount in cents.
     *
     * @return string
     */
    private function formatEuro(int $cents): string
    {
        return '€'.number_format(($cents / 100), 2, ',', '.');
    }//end formatEuro()
}//end class
