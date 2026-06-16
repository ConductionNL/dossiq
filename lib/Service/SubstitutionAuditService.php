<?php

/**
 * Procest SubstitutionAuditService.
 *
 * Capacity audit trail for actions performed under an active substitution.
 * When a substitute mutates a case/task that is in their werkvoorraad only by
 * virtue of an active substitution, the mutation is stamped onto the case
 * activity log with the acting user, the absentee, and the substitution id, so
 * the case timeline can render the action as taken "namens {absentee}
 * (waarneming)". Actions on the actor's own work are never stamped.
 *
 * The case `activity` property holds a JSON-encoded array; entries are appended
 * losslessly (stringify on write, decode on read).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Appends and queries capacity-stamped activity entries.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionAuditService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService     $settingsService The settings/config bridge.
     * @param SubstitutionService $substitutionService Capacity resolution.
     * @param LoggerInterface     $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SubstitutionService $substitutionService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Stamp a capacity entry on a case if the actor acted under substitution.
     *
     * Resolves whether the actor is acting on the case by virtue of an active
     * substitution (the case is assigned to a different absentee). If so, an
     * activity entry carrying `actedOnBehalfOf` + `substitutionId` is appended
     * to the case and the entry is returned. When the actor acts on their own
     * work (no covering substitution), nothing is written and null is returned.
     *
     * @param string $caseId The case being mutated.
     * @param string $actorId The acting user id.
     * @param string $action  A short action label (e.g. "task-completed").
     *
     * @return array<string, mixed>|null The stamped entry, or null when own work.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function stampIfSubstituted(string $caseId, string $actorId, string $action): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $caseSchema    = (string) $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $caseSchema === '' || $caseId === '' || $actorId === '') {
            return null;
        }

        $case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
        if ($case === null) {
            return null;
        }

        $absentee = (string) ($case['assignee'] ?? '');
        if ($absentee === '' || $absentee === $actorId) {
            // Own work (or unassigned) — never capacity-stamped.
            return null;
        }

        $sub = $this->substitutionService->resolveActingCapacity(
            actorId: $actorId,
            absentee: $absentee,
            caseId: $caseId,
            caseType: (isset($case['caseType']) === true ? (string) $case['caseType'] : null)
        );
        if ($sub === null) {
            return null;
        }

        $entry = [
            'type'            => 'substitution-action',
            'action'          => $action,
            'actor'           => $actorId,
            'actedOnBehalfOf' => $absentee,
            'substitutionId'  => (string) ($sub['id'] ?? ($sub['uuid'] ?? '')),
            'timestamp'       => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];

        $activity   = $this->decodeActivity($case['activity'] ?? null);
        $activity[] = $entry;
        $case['activity'] = json_encode($activity);

        try {
            $objectService->updateObject($register, $caseSchema, $caseId, $case);
        } catch (\Throwable $e) {
            $this->logger->error('Substitution capacity stamp failed', ['caseId' => $caseId, 'error' => $e->getMessage()]);
            return null;
        }

        return $entry;
    }//end stampIfSubstituted()

    /**
     * List every capacity-stamped action performed under a substitution.
     *
     * Scans the absentee's cases (the population a substitution can touch) and
     * collects the activity entries that carry the given substitution id,
     * sorted chronologically.
     *
     * @param string $substitutionId The substitution UUID.
     *
     * @return array<int, array<string, mixed>> The stamped actions.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function getActionsForSubstitution(string $substitutionId): array
    {
        if ($substitutionId === '') {
            return [];
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $subSchema     = (string) $this->settingsService->getConfigValue('substitution_schema');
        $caseSchema    = (string) $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $subSchema === '' || $caseSchema === '') {
            return [];
        }

        $sub = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $subSchema, id: $substitutionId);
        if ($sub === null) {
            return [];
        }

        $absentee = (string) ($sub['absentee'] ?? '');
        if ($absentee === '') {
            return [];
        }

        $cases = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $caseSchema,
            filters: ['assignee' => $absentee]
        );

        $actions = [];
        foreach ($cases as $case) {
            $caseId = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            foreach ($this->decodeActivity($case['activity'] ?? null) as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }

                if ((string) ($entry['substitutionId'] ?? '') !== $substitutionId) {
                    continue;
                }

                $entry['caseId'] = $caseId;
                $entry['caseTitle'] = (string) ($case['title'] ?? '');
                $actions[] = $entry;
            }
        }//end foreach

        usort(
            $actions,
            static function (array $a, array $b): int {
                return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
            }
        );

        return $actions;
    }//end getActionsForSubstitution()

    /**
     * Decode the case activity JSON string into an array of entries.
     *
     * @param mixed $raw The raw activity property (JSON string or array).
     *
     * @return array<int, mixed>
     */
    private function decodeActivity(mixed $raw): array
    {
        if (is_array($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        }

        return [];
    }//end decodeActivity()
}//end class
