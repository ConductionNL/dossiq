<?php

/**
 * Procest ConflictOfInterestService.
 *
 * Belangenconflict detection for mandate decisions:
 *
 *   - Automatic detection: extract applicant BSN from the case, walk a
 *     relationship lookup (BRP integration — pluggable here, stub by
 *     default) for the userId's BSN.
 *   - Manual registration: caseworker can register a conflict reason
 *     against a case; subsequent isAuthorized() checks see it and
 *     return belangenconflict.
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
 * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\External\Brp\BrpHaalCentraalAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Belangenconflict detection.
 */
class ConflictOfInterestService
{
    /**
     * Manually-registered conflicts keyed by zaakId.
     *
     * @var array<string, string>
     */
    private array $registered = [];

    /**
     * In-memory relationship index for tests; production wires a BRP
     * adapter via setRelationshipLookup().
     *
     * @var callable|null
     */
    private $relationshipLookup = null;

    /**
     * Constructor.
     *
     * @param LoggerInterface                      $logger      Logger.
     * @param BrpHaalCentraalAdapterInterface|null $brpAdapter  Optional BRP Haal
     *                                                          Centraal adapter
     *                                                          for relationship
     *                                                          enrichment.
     *                                                          Dormant by default.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?BrpHaalCentraalAdapterInterface $brpAdapter = null,
    ) {
    }//end __construct()

    /**
     * Configure the relationship-lookup callable.
     *
     * The callable signature is `(userBsn, applicantBsn): string|null`
     * returning a relationship label (e.g. "spouse", "parent") or null.
     *
     * @param callable $cb Lookup callable.
     *
     * @return void
     *
     * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
     */
    public function setRelationshipLookup(callable $cb): void
    {
        $this->relationshipLookup = $cb;
    }//end setRelationshipLookup()

    /**
     * Check whether the user has a belangenconflict with the case applicant.
     *
     * @param string               $userId        User id.
     * @param string               $zaakId        Case id.
     * @param array<string, mixed> $caseProperties Case properties (must contain applicantBsn + userBsn for auto-detection).
     *
     * @return array{conflict:bool, reason?:string}
     *
     * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
     */
    public function checkConflict(string $userId, string $zaakId, array $caseProperties = []): array
    {
        $this->logger->debug('Conflict-of-interest probe', ['userId' => $userId, 'zaakId' => $zaakId]);

        // Manual registration trumps automatic detection.
        if (isset($this->registered[$zaakId]) === true) {
            return ['conflict' => true, 'reason' => $this->registered[$zaakId]];
        }

        $userBsn      = (string) ($caseProperties['userBsn'] ?? '');
        $applicantBsn = (string) ($caseProperties['applicantBsn'] ?? '');
        if ($userBsn === '' || $applicantBsn === '') {
            return ['conflict' => false];
        }

        if ($userBsn === $applicantBsn) {
            return ['conflict' => true, 'reason' => 'self'];
        }

        if ($this->relationshipLookup !== null) {
            try {
                $relation = ($this->relationshipLookup)($userBsn, $applicantBsn);
            } catch (\Throwable $e) {
                $this->logger->warning('Relationship lookup failed', ['error' => $e->getMessage()]);
                return ['conflict' => false];
            }

            if (is_string($relation) === true && $relation !== '') {
                return ['conflict' => true, 'reason' => $relation];
            }
        }

        // BRP adapter fallback — dormant by default; an active binding looks
        // up the user's relationship to the applicant via Haal Centraal
        // `relaties` envelope and short-circuits with `belangenconflict`.
        $brpRelation = $this->lookupRelationViaBrp($userBsn, $applicantBsn, $zaakId);
        if ($brpRelation !== null && $brpRelation !== '') {
            return ['conflict' => true, 'reason' => $brpRelation];
        }

        return ['conflict' => false];
    }//end checkConflict()

    /**
     * Consult the BRP / Haal Centraal adapter for a relationship label.
     *
     * The adapter ships dormant by default; the LOOKUP_DEFERRED outcome
     * yields null so the conflict check stays open. An active binding
     * returns the user's relation (e.g. `partner`, `parent`, `child`)
     * via the persoon envelope's `relaties` block.
     *
     * Per AVG / WBP article 9 the BSN values themselves are NEVER logged
     * — the dormant adapter redacts them, and this caller never forwards
     * them to the structured logger.
     *
     * @param string $userBsn      User BSN.
     * @param string $applicantBsn Applicant BSN.
     * @param string $zaakId       Case id (audit correlation).
     *
     * @return string|null Relationship label, or null when unknown / dormant.
     *
     * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
     */
    private function lookupRelationViaBrp(string $userBsn, string $applicantBsn, string $zaakId): ?string
    {
        if ($this->brpAdapter === null) {
            return null;
        }

        try {
            $result = $this->brpAdapter->lookup(
                $userBsn,
                [
                    'lookupReason'        => 'belangenconflict-detection',
                    'caseId'              => $zaakId,
                    'comparisonBsnHash'   => substr(hash('sha256', $applicantBsn), 0, 16),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'BRP relationship lookup failed',
                ['zaakId' => $zaakId, 'error' => $e->getMessage()]
            );
            return null;
        }

        if ($result->lookupStatus !== 'FOUND') {
            return null;
        }

        $relations = (array) ($result->persoon['relaties'] ?? []);
        foreach ($relations as $relation) {
            if (is_array($relation) === false) {
                continue;
            }
            $relatedBsn = (string) ($relation['burgerservicenummer'] ?? '');
            if ($relatedBsn === '' || $relatedBsn !== $applicantBsn) {
                continue;
            }
            $label = (string) ($relation['relatie'] ?? $relation['type'] ?? '');
            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }//end lookupRelationViaBrp()

    /**
     * Manually register a belangenconflict on a case.
     *
     * @param string $zaakId Case id.
     * @param string $reason Reason.
     *
     * @return void
     *
     * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
     */
    public function registerConflict(string $zaakId, string $reason): void
    {
        $this->registered[$zaakId] = $reason;
    }//end registerConflict()

    /**
     * Clear a manually-registered conflict.
     *
     * @param string $zaakId Case id.
     *
     * @return void
     *
     * @spec openspec/changes/mandaat-matrix-06-temporal-and-conflict/tasks.md
     */
    public function clearConflict(string $zaakId): void
    {
        unset($this->registered[$zaakId]);
    }//end clearConflict()
}//end class
