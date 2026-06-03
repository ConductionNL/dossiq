<?php

/**
 * Procest Leges Context Resolver
 *
 * Resolves the supplementary context a leges calculation needs to evaluate
 * variant and discount conditions: applicant age (from a BRP birth date),
 * household income / minima status, and the number of months since the
 * applicant's previous comparable application (herhaalaanvraag).
 *
 * The resolver degrades gracefully: when a source is unavailable it returns a
 * null for that datum rather than throwing, so a calculation can still proceed
 * (a missing datum simply means the corresponding condition does not match).
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-004
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-007
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves leeftijd / minima / herhaalaanvraag context for a case.
 */
class LegesContextResolver
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the supplementary context for a case.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return array{
     *     leeftijd: int|null,
     *     huishoudinkomen: int|null,
     *     herhaalaanvraag_maanden: int|null,
     *     minima_geverifieerd: bool,
     *     grondslagWaarden: array<string, mixed>
     * }
     */
    public function resolve(array $case): array
    {
        return [
            'leeftijd'                => $this->resolveLeeftijd(case: $case),
            'huishoudinkomen'         => $this->resolveInkomen(case: $case),
            'herhaalaanvraag_maanden' => $this->resolveHerhaalaanvraagMaanden(case: $case),
            'minima_geverifieerd'     => (bool) ($case['minimaGeverifieerd'] ?? false),
            'grondslagWaarden'        => $this->collectGrondslagWaarden(case: $case),
        ];
    }//end resolve()

    /**
     * Resolve applicant age in whole years from a BRP/case birth date.
     *
     * Prefers an already-resolved 'leeftijd' attribute; otherwise derives it
     * from a 'geboortedatum' field on the case.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return int|null
     */
    private function resolveLeeftijd(array $case): ?int
    {
        if (isset($case['leeftijd']) === true && is_numeric($case['leeftijd']) === true) {
            return (int) $case['leeftijd'];
        }

        $geboortedatum = (string) ($case['geboortedatum'] ?? '');
        if ($geboortedatum === '') {
            return null;
        }

        try {
            $birth = new DateTimeImmutable($geboortedatum);
        } catch (Throwable $e) {
            $this->logger->debug('Procest leges: invalid geboortedatum on case: '.$e->getMessage());
            return null;
        }

        $now = new DateTimeImmutable();
        return (int) $now->diff($birth)->y;
    }//end resolveLeeftijd()

    /**
     * Resolve household income (eurocents) when present on the case.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return int|null
     */
    private function resolveInkomen(array $case): ?int
    {
        if (isset($case['huishoudinkomen']) === true && is_numeric($case['huishoudinkomen']) === true) {
            return (int) $case['huishoudinkomen'];
        }

        return null;
    }//end resolveInkomen()

    /**
     * Resolve the number of months since the previous comparable application.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return int|null
     */
    private function resolveHerhaalaanvraagMaanden(array $case): ?int
    {
        $vorige = (string) ($case['vorigeAanvraagDatum'] ?? '');
        if ($vorige === '') {
            return null;
        }

        try {
            $previous = new DateTimeImmutable($vorige);
        } catch (Throwable $e) {
            return null;
        }

        $now  = new DateTimeImmutable();
        $diff = $now->diff($previous);
        return (int) (($diff->y * 12) + $diff->m);
    }//end resolveHerhaalaanvraagMaanden()

    /**
     * Collect raw grondslag values from the case for audit-trail rendering.
     *
     * @param array<string, mixed> $case The case object.
     *
     * @return array<string, mixed>
     */
    private function collectGrondslagWaarden(array $case): array
    {
        $waarden = [];
        foreach (['bouwsom', 'oppervlakte', 'leeftijd', 'huishoudinkomen'] as $veld) {
            if (isset($case[$veld]) === true) {
                $waarden[$veld] = $case[$veld];
            }
        }

        return $waarden;
    }//end collectGrondslagWaarden()
}//end class
