<?php

/**
 * Procest LHS Recommendation Service
 *
 * Single entry point for the Landelijke Handhavingsstrategie (LHS) lookup:
 *   - `recommend(ernst, gedrag, actorType, lhsVersion?)` returns the prescribed
 *     intervention by reading a cell from the active (or explicitly versioned)
 *     `lhsMatrix` and persists an `lhsRecommendation` row.
 *   - `override(recommendation, intervention, justification, userRole)` applies
 *     an inspector override. Override-up (harsher than recommended) is gated to
 *     the manager role per REQ-LHS-5/6.
 *
 * CRUD over matrices and recommendations themselves is served by the
 * OpenRegister manifest renderer; this service owns the engine actions.
 *
 * @category Service
 * @package  OCA\Procest\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Vth;

use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * LHS recommendation engine.
 *
 * @spec openspec/changes/enforcement-lhs/tasks.md#T03
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates OpenRegister,
 *   user-session, settings bridge, and logger.
 *
 * @psalm-suppress UnusedClass
 */
class LhsRecommendationService
{
    /**
     * Severity ordering of the `interventie` enum, lowest -> highest.
     *
     * Used by override() to determine whether an inspector is overriding
     * "up" (harsher, manager-only) or "down" (lighter, any inspector).
     *
     * @var array<string, int>
     */
    private const INTERVENTIE_SEVERITY = [
        'waarschuwing'          => 1,
        'herstelactie'          => 2,
        'last_onder_dwangsom'   => 3,
        'last_plus_pv'          => 4,
        'bestuursdwang'         => 5,
        'pv_plus_bestuursdwang' => 6,
    ];

    /**
     * Minimum length of override justification (non-whitespace chars).
     */
    private const MIN_JUSTIFICATION_LENGTH = 20;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings bridge
     * @param IUserSession    $userSession     Authenticated user session
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Recommend an intervention for the given (ernst, gedrag, actorType).
     *
     * Loads the matrix (active by default, or the explicitly requested
     * version), maps cells into an in-memory dictionary keyed
     * "ernst:gedrag:actorType", and persists an `lhsRecommendation` row
     * carrying the lookup result. Identity is always derived from the
     * session — never from caller-supplied data.
     *
     * @param string      $caseId     The parent case UUID
     * @param string      $ernst      Severity axis value
     * @param string      $gedrag     Behaviour axis value
     * @param string      $actorType  Actor-type axis value
     * @param int|null    $lhsVersion Optional explicit matrix version; null = active
     * @param string|null $inspection Optional inspection rapport UUID for traceability
     *
     * @return array<string, mixed> The persisted recommendation row
     *
     * @throws RuntimeException When OpenRegister is unavailable, no matching
     *                          matrix exists, or no cell matches the triple.
     */
    /** @spec openspec/specs/enforcement-lhs/spec.md */
    public function recommend(
        string $caseId,
        string $ernst,
        string $gedrag,
        string $actorType,
        ?int $lhsVersion=null,
        ?string $inspection=null,
    ): array {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('Authenticatie vereist voor LHS-aanbeveling');
        }

        $matrix    = $this->loadMatrix(version: $lhsVersion);
        $cellIndex = $this->indexCells(cells: ($matrix['cells'] ?? []));
        $key       = $ernst.':'.$gedrag.':'.$actorType;

        if (isset($cellIndex[$key]) === false) {
            throw new RuntimeException(
                'Geen LHS-cel gevonden voor combinatie '.$key
            );
        }

        $cell           = $cellIndex[$key];
        $recommendation = [
            'case'                   => $caseId,
            'inspection'             => $inspection,
            'ernst'                  => $ernst,
            'gedrag'                 => $gedrag,
            'actorType'              => $actorType,
            'matrixVersion'          => (int) ($matrix['version'] ?? 1),
            'recommendedInterventie' => (string) ($cell['interventie'] ?? ''),
            'finalIntervention'      => (string) ($cell['interventie'] ?? ''),
            'override'               => false,
            'recommendedBy'          => $user->getUID(),
        ];

        if ($inspection === null) {
            unset($recommendation['inspection']);
        }

        return $this->persistRecommendation(row: $recommendation);
    }//end recommend()

    /**
     * Apply an override to an existing LHS recommendation.
     *
     * Override-down (selecting an intervention of equal or lower severity than
     * the recommendation) is allowed for any inspector with a justification of
     * at least 20 non-whitespace characters. Override-up (harsher than the
     * recommendation) requires the caller to declare the manager role and is
     * persisted with `overrideAuthority = "manager"`.
     *
     * @param array<string, mixed> $recommendation Original recommendation row
     *                                             (must include id, recommendedInterventie)
     * @param string               $intervention   Chosen intervention (enum value)
     * @param string               $justification  Mandatory justification (>= 20 chars)
     * @param string               $userRole       Caller role: "inspector" or "manager"
     *
     * @return array<string, mixed> The updated recommendation row
     *
     * @throws RuntimeException When validation fails (HTTP-mapped by controller).
     */
    /** @spec openspec/specs/enforcement-lhs/spec.md */
    public function override(
        array $recommendation,
        string $intervention,
        string $justification,
        string $userRole,
    ): array {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('Authenticatie vereist voor override');
        }

        $trimmed = preg_replace('/\s+/u', '', $justification) ?? '';
        if (mb_strlen($trimmed) < self::MIN_JUSTIFICATION_LENGTH) {
            throw new RuntimeException(
                'Motivatie afwijking moet minimaal 20 tekens bevatten'
            );
        }

        $recommended = (string) ($recommendation['recommendedInterventie'] ?? '');
        $recSeverity = self::INTERVENTIE_SEVERITY[$recommended] ?? 0;
        $newSeverity = self::INTERVENTIE_SEVERITY[$intervention] ?? 0;
        if ($newSeverity === 0) {
            throw new RuntimeException(
                'Ongeldige interventie: '.$intervention
            );
        }

        $overrideUp = ($newSeverity > $recSeverity);
        $authority  = 'inspector';
        if ($userRole === 'manager') {
            $authority = 'manager';
        }

        if ($overrideUp === true && $authority !== 'manager') {
            throw new RuntimeException('Verzwaring vereist managerrol');
        }

        $recommendationId = (string) ($recommendation['id'] ?? ($recommendation['@self']['id'] ?? ''));
        if ($recommendationId === '') {
            throw new RuntimeException('Recommendation ID ontbreekt voor override');
        }

        $updated = array_merge(
            $recommendation,
            [
                'override'              => true,
                'overrideJustification' => $justification,
                'overrideBy'            => $user->getUID(),
                'overrideAuthority'     => $authority,
                'finalIntervention'     => $intervention,
            ]
        );

        return $this->persistRecommendation(row: $updated, id: $recommendationId);
    }//end override()

    /**
     * Load the LHS matrix to use for the lookup.
     *
     * Without an explicit version, returns the matrix flagged `active = true`.
     * With an explicit version, returns the matching versioned snapshot —
     * used by historical recommendations that must remain stable across
     * subsequent matrix edits (REQ-LHS-8).
     *
     * @param int|null $version Explicit matrix version, or null for active
     *
     * @return array<string, mixed> The matrix row
     *
     * @throws RuntimeException When no matrix is available.
     */
    private function loadMatrix(?int $version): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('lhs_matrix_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('LHS-matrix register/schema is niet geconfigureerd');
        }

        $filters = ['active' => true];
        if ($version !== null) {
            $filters = ['version' => $version];
        }

        try {
            $results = $objectService->findAll(
                [
                    'filters' => (['register' => $register, 'schema' => $schema] + $filters),
                    'limit'   => 1,
                ],
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest LHS: matrix lookup failed: '.$e->getMessage(),
            );
            throw new RuntimeException('LHS-matrix lookup mislukt');
        }

        $row = $this->firstRow(results: $results);
        if ($row === null) {
            throw new RuntimeException('Geen actieve LHS-matrix gevonden');
        }

        return $this->toArray(value: $row);
    }//end loadMatrix()

    /**
     * Persist an lhsRecommendation row through ObjectService.
     *
     * @param array<string, mixed> $row Row to persist
     * @param string|null          $id  Existing id when updating; null for create
     *
     * @return array<string, mixed> Persisted row
     *
     * @throws RuntimeException On save failure.
     */
    private function persistRecommendation(array $row, ?string $id=null): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('lhs_recommendation_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('LHS-recommendation register/schema is niet geconfigureerd');
        }

        try {
            if ($id !== null) {
                $row['id'] = $id;
            }

            $saved = $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: $row,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest LHS: failed to save lhsRecommendation: '.$e->getMessage(),
            );
            throw new RuntimeException('Opslaan LHS-aanbeveling mislukt');
        }

        return $this->toArray(value: $saved);
    }//end persistRecommendation()

    /**
     * Build an in-memory dictionary of cells keyed `ernst:gedrag:actorType`.
     *
     * Accepts both a JSON-encoded string (as stored on some legacy rows)
     * and a native array.
     *
     * @param mixed $cells The cells field of the matrix row
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexCells(mixed $cells): array
    {
        if (is_string($cells) === true) {
            $decoded = json_decode($cells, true);
            $cells   = [];
            if (is_array($decoded) === true) {
                $cells = $decoded;
            }
        }

        if (is_array($cells) === false) {
            return [];
        }

        $index = [];
        foreach ($cells as $cell) {
            if (is_array($cell) === false) {
                continue;
            }

            $key         = ((string) ($cell['ernst'] ?? ''))
                .':'.((string) ($cell['gedrag'] ?? ''))
                .':'.((string) ($cell['actorType'] ?? ''));
            $index[$key] = $cell;
        }

        return $index;
    }//end indexCells()

    /**
     * Pluck the first row from any ObjectService result shape.
     *
     * @param mixed $results ObjectService::getObjects() return
     *
     * @return mixed|null
     */
    private function firstRow(mixed $results): mixed
    {
        if (is_array($results) === true) {
            if (isset($results[0]) === true) {
                return $results[0];
            }

            if (isset($results['results']) === true
                && is_array($results['results']) === true
                && count($results['results']) > 0
            ) {
                return $results['results'][0];
            }
        }

        return null;
    }//end firstRow()

    /**
     * Coerce an ObjectService return value to an associative array.
     *
     * @param mixed $value The value
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialised = $value->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
