<?php

/**
 * Procest SubstitutionService.
 *
 * Vervanging/waarneming (handler absence/substitution) domain logic on top of
 * OpenRegister RBAC. Substitution records describe who covers whom, when, and
 * for what scope; creating one grants nothing by itself. Resolution answers
 * "whose work should this user also see right now?" and is consumed by the My
 * Work query layer and the notification fan-out. All resolved work items are
 * filtered through the substitute's own OpenRegister RBAC effective
 * permissions — the service never elevates.
 *
 * Decision/besluit authority during absence is explicitly out of scope and
 * remains owned by the mandaat-matrix (waarnemer on the decision date); this
 * service handles workload routing/visibility only.
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
use InvalidArgumentException;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves vervanging/waarneming substitutions and the workload they route.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionService
{
    use SearchesObjects;

    /**
     * Per-request cache of active substitutions keyed by "userId|date".
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $activeCache = [];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings/config + ObjectService bridge.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a substitution after validation.
     *
     * Rejects self-substitution, missing/invalid period, and a same-period
     * overlapping full-scope substitution for the same absentee. A disjoint
     * scope for the same period is accepted.
     *
     * @param string             $absentee   Handler being covered (user id).
     * @param string             $substitute Waarnemer (user id).
     * @param string             $startDate  Inclusive start (Y-m-d).
     * @param string             $endDate    Inclusive end (Y-m-d), required.
     * @param string             $scope      One of all|caseTypes|cases.
     * @param array<int, string> $scopeRefs  caseType/case UUIDs when narrowed.
     * @param string             $reason     One of verlof|ziekte|anders.
     * @param string             $createdBy  Creating user id (self or coordinator).
     * @param string             $comment    Optional free-text comment.
     *
     * @return array<string, mixed> The created substitution object.
     *
     * @throws InvalidArgumentException On any validation failure.
     * @throws \RuntimeException        When OpenRegister is unavailable.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function create(
        string $absentee,
        string $substitute,
        string $startDate,
        string $endDate,
        string $scope='all',
        array $scopeRefs=[],
        string $reason='verlof',
        string $createdBy='',
        string $comment=''
    ): array {
        $absentee   = trim($absentee);
        $substitute = trim($substitute);

        if ($absentee === '' || $substitute === '') {
            throw new InvalidArgumentException('Both absentee and substitute are required');
        }

        if ($absentee === $substitute) {
            throw new InvalidArgumentException('A handler cannot be their own waarnemer (self-substitution is not allowed)');
        }

        if (in_array($scope, ['all', 'caseTypes', 'cases'], true) === false) {
            throw new InvalidArgumentException('Invalid scope; expected all, caseTypes or cases');
        }

        if (in_array($reason, ['verlof', 'ziekte', 'anders'], true) === false) {
            throw new InvalidArgumentException('Invalid reason; expected verlof, ziekte or anders');
        }

        $start = $this->parseDate(value: $startDate);
        $end   = $this->parseDate(value: $endDate);
        if ($start === null) {
            throw new InvalidArgumentException('A valid startDate (YYYY-MM-DD) is required');
        }

        if ($end === null) {
            throw new InvalidArgumentException(
                'A valid endDate (YYYY-MM-DD) is required; open-ended absences must be re-issued or converted to a bulk reassignment'
            );
        }

        if ($end < $start) {
            throw new InvalidArgumentException('endDate must not be before startDate');
        }

        if (($scope === 'caseTypes' || $scope === 'cases') && count($scopeRefs) === 0) {
            throw new InvalidArgumentException('scopeRefs is required when scope is caseTypes or cases');
        }

        $this->assertNoOverlappingFullScope(
            absentee: $absentee,
            scope: $scope,
            start: $start,
            end: $end
        );

        if ($createdBy !== '') {
            $createdByValue = $createdBy;
        } else {
            $createdByValue = $absentee;
        }

        $row = [
            'absentee'   => $absentee,
            'substitute' => $substitute,
            'startDate'  => $start->format('Y-m-d'),
            'endDate'    => $end->format('Y-m-d'),
            'scope'      => $scope,
            'scopeRefs'  => array_values($scopeRefs),
            'reason'     => $reason,
            'comment'    => $comment,
            'status'     => 'active',
            'createdBy'  => $createdByValue,
        ];

        [$objectService, $register, $schema] = $this->context();
        $saved = $objectService->saveObject($register, $schema, $row);
        $this->activeCache = [];

        if (is_array($saved) === true) {
            return $saved;
        }

        return $row;
    }//end create()

    /**
     * Revoke a substitution immediately (status -> revoked).
     *
     * @param string $id The substitution UUID.
     *
     * @return array<string, mixed>|null The updated object, or null when not found.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function revoke(string $id): ?array
    {
        [$objectService, $register, $schema] = $this->context();
        $existing = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
        if ($existing === null) {
            return null;
        }

        $existing['status'] = 'revoked';
        $saved = $objectService->updateObject($register, $schema, $id, $existing);
        $this->activeCache = [];

        if (is_array($saved) === true) {
            return $saved;
        }

        return $existing;
    }//end revoke()

    /**
     * Resolve the substitutions that are active for a substitute on a date.
     *
     * A substitution is active when status == active AND start <= date <= end.
     * Records whose endDate has passed are lazily marked `ended` (best-effort
     * persistence) and excluded. Results are cached per request.
     *
     * @param string                 $userId The waarnemer (substitute) user id.
     * @param DateTimeImmutable|null $date   Reference date; defaults to today.
     *
     * @return array<int, array<string, mixed>> The active substitution records.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function getActiveSubstitutionsFor(string $userId, ?DateTimeImmutable $date=null): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        $ref      = ($date ?? new DateTimeImmutable('today'));
        $refDay   = $ref->format('Y-m-d');
        $cacheKey = $userId.'|'.$refDay;
        if (isset($this->activeCache[$cacheKey]) === true) {
            return $this->activeCache[$cacheKey];
        }

        [$objectService, $register, $schema] = $this->context(strict: false);
        if ($objectService === null) {
            return [];
        }

        $rows = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['substitute' => $userId]
        );

        $active = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'revoked') {
                continue;
            }

            $start = (string) ($row['startDate'] ?? '');
            $end   = (string) ($row['endDate'] ?? '');

            // Lazy expiry: past endDate -> ended, excluded.
            if ($end !== '' && $refDay > $end) {
                if ($status === 'active') {
                    $this->markEnded(
                        objectService: $objectService,
                        register: $register,
                        schema: $schema,
                        row: $row
                    );
                }

                continue;
            }

            if ($status !== 'active') {
                continue;
            }

            if ($start !== '' && $refDay < $start) {
                continue;
            }

            $active[] = $row;
        }//end foreach

        $this->activeCache[$cacheKey] = $active;

        return $active;
    }//end getActiveSubstitutionsFor()

    /**
     * Resolve the substituted open cases and tasks routed to a waarnemer.
     *
     * For each active substitution the absentee's open cases/tasks within the
     * substitution scope are gathered. Because the OpenRegister ObjectService
     * search runs in the calling user's (the substitute's) RBAC context, items
     * the substitute cannot read are already excluded — the service never
     * elevates. Each returned item is annotated with the substitution context
     * so the UI can render the "waargenomen voor {naam}" badge.
     *
     * @param string                 $userId The waarnemer (substitute) user id.
     * @param DateTimeImmutable|null $date   Reference date; defaults to today.
     *
     * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function getSubstitutedWorkFor(string $userId, ?DateTimeImmutable $date=null): array
    {
        $result = ['cases' => [], 'tasks' => []];
        $subs   = $this->getActiveSubstitutionsFor(userId: $userId, date: $date);
        if (count($subs) === 0) {
            return $result;
        }

        [$objectService, $register] = $this->context(strict: false);
        if ($objectService === null) {
            return $result;
        }

        $caseSchema = (string) $this->settingsService->getConfigValue('case_schema');
        $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');
        $finalIds   = $this->finalStatusIds(objectService: $objectService, register: $register);

        $seenCases = [];
        $seenTasks = [];

        foreach ($subs as $sub) {
            $absentee  = (string) ($sub['absentee'] ?? '');
            $subId     = (string) ($sub['id'] ?? ($sub['uuid'] ?? ''));
            $scope     = (string) ($sub['scope'] ?? 'all');
            $scopeRefs = array_map('strval', (array) ($sub['scopeRefs'] ?? []));
            if ($absentee === '') {
                continue;
            }

            // Cases the absentee handles.
            if ($caseSchema !== '') {
                $cases = $this->searchObjectsAsArrays(
                    objectService: $objectService,
                    register: $register,
                    schema: $caseSchema,
                    filters: ['assignee' => $absentee]
                );
                foreach ($cases as $case) {
                    if ($this->caseInScope(case: $case, scope: $scope, scopeRefs: $scopeRefs) === false) {
                        continue;
                    }

                    if (in_array((string) ($case['status'] ?? ''), $finalIds, true) === true) {
                        continue;
                    }

                    $id = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
                    if ($id === '' || isset($seenCases[$id]) === true) {
                        continue;
                    }

                    $seenCases[$id]       = true;
                    $case['_substituted'] = ['absentee' => $absentee, 'substitutionId' => $subId];
                    $result['cases'][]    = $case;
                }//end foreach
            }//end if

            // Tasks the absentee is assigned (open = not completed/terminated).
            if ($taskSchema !== '') {
                $tasks = $this->searchObjectsAsArrays(
                    objectService: $objectService,
                    register: $register,
                    schema: $taskSchema,
                    filters: ['assignee' => $absentee]
                );
                foreach ($tasks as $task) {
                    $tStatus = (string) ($task['status'] ?? '');
                    if (in_array($tStatus, ['completed', 'terminated', 'disabled'], true) === true) {
                        continue;
                    }

                    if ($scope === 'cases' && in_array((string) ($task['case'] ?? ''), $scopeRefs, true) === false) {
                        continue;
                    }

                    $id = (string) ($task['id'] ?? ($task['uuid'] ?? ''));
                    if ($id === '' || isset($seenTasks[$id]) === true) {
                        continue;
                    }

                    $seenTasks[$id]       = true;
                    $task['_substituted'] = ['absentee' => $absentee, 'substitutionId' => $subId];
                    $result['tasks'][]    = $task;
                }//end foreach
            }//end if
        }//end foreach

        return $result;
    }//end getSubstitutedWorkFor()

    /**
     * Resolve the active substitution under which the given user may act on an
     * item assigned to a different absentee — used for capacity stamping.
     *
     * Returns the matching substitution (so the caller can stamp
     * actedOnBehalfOf + substitutionId) or null when the user is acting on
     * their own work / no active substitution covers the item.
     *
     * @param string                 $actorId  The acting user id.
     * @param string                 $absentee The item's current assignee.
     * @param string                 $caseId   The case id (for scope checks).
     * @param string|null            $caseType The case's caseType (for scope checks).
     * @param DateTimeImmutable|null $date     Reference date; defaults to today.
     *
     * @return array<string, mixed>|null The covering substitution, or null.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function resolveActingCapacity(
        string $actorId,
        string $absentee,
        string $caseId='',
        ?string $caseType=null,
        ?DateTimeImmutable $date=null
    ): ?array {
        if ($actorId === '' || $absentee === '' || $actorId === $absentee) {
            return null;
        }

        foreach ($this->getActiveSubstitutionsFor(userId: $actorId, date: $date) as $sub) {
            if ((string) ($sub['absentee'] ?? '') !== $absentee) {
                continue;
            }

            $scope     = (string) ($sub['scope'] ?? 'all');
            $scopeRefs = array_map('strval', (array) ($sub['scopeRefs'] ?? []));
            $probe     = ['caseType' => $caseType, 'id' => $caseId];
            if ($this->caseInScope(case: $probe, scope: $scope, scopeRefs: $scopeRefs) === true) {
                return $sub;
            }
        }

        return null;
    }//end resolveActingCapacity()

    /**
     * Whether a case falls within a substitution scope.
     *
     * @param array<string, mixed> $case      The case object.
     * @param string               $scope     all|caseTypes|cases.
     * @param array<int, string>   $scopeRefs The narrowed refs.
     *
     * @return bool
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    private function caseInScope(array $case, string $scope, array $scopeRefs): bool
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
     * Reject a new substitution that overlaps an existing active full-scope one.
     *
     * Only `all`+`all` overlaps for the same absentee and period are rejected;
     * disjoint scopes are allowed to coexist.
     *
     * @param string            $absentee The covered handler.
     * @param string            $scope    The new substitution scope.
     * @param DateTimeImmutable $start    New start.
     * @param DateTimeImmutable $end      New end.
     *
     * @return void
     *
     * @throws InvalidArgumentException When a conflicting full-scope substitution exists.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    private function assertNoOverlappingFullScope(string $absentee, string $scope, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        if ($scope !== 'all') {
            return;
        }

        [$objectService, $register, $schema] = $this->context(strict: false);
        if ($objectService === null) {
            return;
        }

        $rows = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['absentee' => $absentee]
        );

        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'active') {
                continue;
            }

            if ((string) ($row['scope'] ?? '') !== 'all') {
                continue;
            }

            $existingStart = $this->parseDate(value: (string) ($row['startDate'] ?? ''));
            $existingEnd   = $this->parseDate(value: (string) ($row['endDate'] ?? ''));
            if ($existingStart === null || $existingEnd === null) {
                continue;
            }

            // Overlap when neither range is entirely before the other.
            if ($start <= $existingEnd && $existingStart <= $end) {
                $conflictId = (string) ($row['id'] ?? ($row['uuid'] ?? '?'));
                throw new InvalidArgumentException(
                    'An active full-scope substitution already covers this handler for an '
                    .'overlapping period (conflicting substitution: '.$conflictId.')'
                );
            }
        }//end foreach
    }//end assertNoOverlappingFullScope()

    /**
     * Best-effort lazy persistence of the `ended` status.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      Register id.
     * @param string               $schema        Schema id.
     * @param array<string, mixed> $row           The expired substitution row.
     *
     * @return void
     */
    private function markEnded(object $objectService, string $register, string $schema, array $row): void
    {
        $id = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
        if ($id === '') {
            return;
        }

        try {
            $row['status'] = 'ended';
            $objectService->updateObject($register, $schema, $id, $row);
        } catch (\Throwable $e) {
            $this->logger->warning('Substitution lazy-ended persistence failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }//end markEnded()

    /**
     * Resolve the set of final statusType ids (closed/archived cases).
     *
     * @param object $objectService The ObjectService.
     * @param string $register      Register id.
     *
     * @return array<int, string>
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

    /**
     * Parse a YYYY-MM-DD date string into an immutable date (midnight).
     *
     * @param string $value The date string.
     *
     * @return DateTimeImmutable|null
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            return null;
        }

        return $date;
    }//end parseDate()

    /**
     * Resolve the ObjectService + register + substitution schema context.
     *
     * @param bool $strict When true, throw if any piece is missing.
     *
     * @return array{0: object|null, 1: string, 2: string}
     *
     * @throws \RuntimeException When strict and OpenRegister is unavailable.
     */
    private function context(bool $strict=true): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('substitution_schema');

        if ($strict === true && ($objectService === null || $register === '' || $schema === '')) {
            throw new RuntimeException('OpenRegister is not available or the substitution schema is not configured');
        }

        return [$objectService, $register, $schema];
    }//end context()
}//end class
