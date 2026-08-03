<?php

/**
 * Procest SubstitutedWorkResolver.
 *
 * Gathers the open cases and tasks that a set of active substitutions routes to
 * a waarnemer. Split out of SubstitutionService, which now only decides WHICH
 * substitutions are active; this collaborator decides WHAT work those
 * substitutions surface.
 *
 * Because the OpenRegister ObjectService search runs in the calling user's (the
 * substitute's) RBAC context, items the substitute cannot read are already
 * excluded — this resolver never elevates.
 *
 * @category Service
 * @package  OCA\Procest\Service\Substitution
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

namespace OCA\Procest\Service\Substitution;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;

/**
 * Resolves the workload a set of active substitutions routes to a waarnemer.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutedWorkResolver
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings/config + ObjectService bridge.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * Resolve the substituted open cases and tasks for a set of active substitutions.
     *
     * Each returned item is annotated with `_substituted` so the UI can render
     * the "waargenomen voor {naam}" badge.
     *
     * @param array<int, array<string, mixed>> $subs The active substitution records.
     *
     * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function resolve(array $subs): array
    {
        $result = ['cases' => [], 'tasks' => []];
        if (count($subs) === 0) {
            return $result;
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        if ($objectService === null) {
            return $result;
        }

        $caseSchema = (string) $this->settingsService->getConfigValue('case_schema');
        $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');
        $finalIds   = $this->finalStatusIds(objectService: $objectService, register: $register);

        $seenCases = [];
        $seenTasks = [];

        foreach ($subs as $sub) {
            $absentee = (string) ($sub['absentee'] ?? '');
            if ($absentee === '') {
                continue;
            }

            if ($caseSchema !== '') {
                $result['cases'] = array_merge(
                    $result['cases'],
                    $this->collectCases(
                        objectService: $objectService,
                        register: $register,
                        caseSchema: $caseSchema,
                        sub: $sub,
                        finalIds: $finalIds,
                        seen: $seenCases
                    )
                );
            }

            if ($taskSchema !== '') {
                $result['tasks'] = array_merge(
                    $result['tasks'],
                    $this->collectTasks(
                        objectService: $objectService,
                        register: $register,
                        taskSchema: $taskSchema,
                        sub: $sub,
                        seen: $seenTasks
                    )
                );
            }
        }//end foreach

        return $result;
    }//end resolve()

    /**
     * Collect the absentee's in-scope, non-final cases for one substitution.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      Register id.
     * @param string               $caseSchema    Case schema id.
     * @param array<string, mixed> $sub           The substitution record.
     * @param array<int, string>   $finalIds      Final statusType ids to exclude.
     * @param array<string, bool>  $seen          Dedup map, mutated in place.
     *
     * @return array<int, array<string, mixed>> The newly collected cases.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    private function collectCases(
        object $objectService,
        string $register,
        string $caseSchema,
        array $sub,
        array $finalIds,
        array &$seen
    ): array {
        $absentee  = (string) ($sub['absentee'] ?? '');
        $subId     = (string) ($sub['id'] ?? ($sub['uuid'] ?? ''));
        $scope     = (string) ($sub['scope'] ?? 'all');
        $scopeRefs = array_map('strval', (array) ($sub['scopeRefs'] ?? []));

        $cases = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $caseSchema,
            filters: ['assignee' => $absentee]
        );

        $collected = [];
        foreach ($cases as $case) {
            if ($this->caseInScope(case: $case, scope: $scope, scopeRefs: $scopeRefs) === false) {
                continue;
            }

            if (in_array((string) ($case['status'] ?? ''), $finalIds, true) === true) {
                continue;
            }

            $id = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            if ($id === '' || isset($seen[$id]) === true) {
                continue;
            }

            $seen[$id]            = true;
            $case['_substituted'] = ['absentee' => $absentee, 'substitutionId' => $subId];
            $collected[]          = $case;
        }//end foreach

        return $collected;
    }//end collectCases()

    /**
     * Collect the absentee's open tasks for one substitution.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      Register id.
     * @param string               $taskSchema    Task schema id.
     * @param array<string, mixed> $sub           The substitution record.
     * @param array<string, bool>  $seen          Dedup map, mutated in place.
     *
     * @return array<int, array<string, mixed>> The newly collected tasks.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    private function collectTasks(
        object $objectService,
        string $register,
        string $taskSchema,
        array $sub,
        array &$seen
    ): array {
        $absentee  = (string) ($sub['absentee'] ?? '');
        $subId     = (string) ($sub['id'] ?? ($sub['uuid'] ?? ''));
        $scope     = (string) ($sub['scope'] ?? 'all');
        $scopeRefs = array_map('strval', (array) ($sub['scopeRefs'] ?? []));

        $tasks = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $taskSchema,
            filters: ['assignee' => $absentee]
        );

        $collected = [];
        foreach ($tasks as $task) {
            $tStatus = (string) ($task['status'] ?? '');
            if (in_array($tStatus, ['completed', 'terminated', 'disabled'], true) === true) {
                continue;
            }

            if ($scope === 'cases' && in_array((string) ($task['case'] ?? ''), $scopeRefs, true) === false) {
                continue;
            }

            $id = (string) ($task['id'] ?? ($task['uuid'] ?? ''));
            if ($id === '' || isset($seen[$id]) === true) {
                continue;
            }

            $seen[$id]            = true;
            $task['_substituted'] = ['absentee' => $absentee, 'substitutionId' => $subId];
            $collected[]          = $task;
        }//end foreach

        return $collected;
    }//end collectTasks()

    /**
     * Whether a case falls within a substitution scope.
     *
     * @param array<string, mixed> $case      The case object.
     * @param string               $scope     all|caseTypes|cases.
     * @param array<int, string>   $scopeRefs The narrowed refs.
     *
     * @return bool True when the case is covered by the scope.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function caseInScope(array $case, string $scope, array $scopeRefs): bool
    {
        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'caseTypes') {
            return in_array((string) ($case['caseType'] ?? ''), $scopeRefs, true);
        }

        if ($scope === 'cases') {
            $id = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
            return in_array($id, $scopeRefs, true);
        }

        return false;
    }//end caseInScope()

    /**
     * Resolve the set of final statusType ids (closed/archived cases).
     *
     * @param object $objectService The ObjectService.
     * @param string $register      Register id.
     *
     * @return array<int, string> The final statusType ids.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    private function finalStatusIds(object $objectService, string $register): array
    {
        $statusTypeSchema = (string) $this->settingsService->getConfigValue('status_type_schema');
        if ($statusTypeSchema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $statusTypeSchema);
        } catch (\Throwable $e) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $isFinal = ($row['isFinal'] ?? false);
            if ($isFinal === true || $isFinal === 'true' || $isFinal === 1) {
                $ids[] = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
            }
        }

        return array_values(array_filter($ids));
    }//end finalStatusIds()
}//end class
