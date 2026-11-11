<?php

/**
 * Dossiq SubstitutedWorkResolver.
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
 * @package  OCA\Dossiq\Service\Substitution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Resolves the workload a set of active substitutions routes to a waarnemer.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutedWorkResolver {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config + ObjectService bridge.
	 * @param EngineTaskInbox $engineTasks The engine's task reader.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineTaskInbox $engineTasks,
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
	public function resolve(array $subs): array {
		$result = ['cases' => [], 'tasks' => []];
		$run = $this->workRun(subs: $subs);
		if ($run === null) {
			return $result;
		}

		foreach (array_filter($subs, static fn (array $sub): bool => (string)($sub['absentee'] ?? '') !== '') as $sub) {
			$run->sub = $sub;
			$result['cases'] = array_merge($result['cases'], $this->collectCases(run: $run));
			$result['tasks'] = array_merge($result['tasks'], $this->collectTasks(sub: $sub, seen: $run->seenTasks));
		}

		return $result;
	}//end resolve()

	/**
	 * Where this gathering reads, or null when there is nothing to gather or no OpenRegister.
	 *
	 * @param array<int, array<string, mixed>> $subs The active substitution records.
	 *
	 * @return SubstitutionWorkRun|null The run.
	 */
	private function workRun(array $subs): ?SubstitutionWorkRun {
		if (count($subs) === 0) {
			return null;
		}

		$run = new SubstitutionWorkRun();
		$objectService = $this->settingsService->getObjectService();
		$run->register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null) {
			return null;
		}

		$run->objectService = $objectService;
		$run->caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$run->finalIds = $this->finalStatusIds(objectService: $objectService, register: $run->register);

		return $run;
	}//end workRun()

	/**
	 * Collect the absentee's in-scope, non-final cases for one substitution.
	 *
	 * @param SubstitutionWorkRun $run The gathering, with the substitution in hand; its seen cases are updated.
	 *
	 * @return array<int, array<string, mixed>> The newly collected cases.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function collectCases(SubstitutionWorkRun $run): array {
		if ($run->caseSchema === '') {
			return [];
		}

		$stamp = $this->stampOf(sub: $run->sub);
		$cases = $this->casesAssignedTo(run: $run, absentee: $stamp['absentee']);
		$collected = [];
		foreach ($cases as $case) {
			if ($this->caseRoutes(run: $run, case: $case) === true) {
				$case['_substituted'] = $stamp;
				$collected[] = $case;
			}
		}

		return $collected;
	}//end collectCases()

	/**
	 * The cases assigned to the absentee, read as the substitute.
	 *
	 * @param SubstitutionWorkRun $run      The gathering.
	 * @param string              $absentee The absentee.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function casesAssignedTo(SubstitutionWorkRun $run, string $absentee): array {
		return $this->searchObjectsAsArrays(
			objectService: $run->objectService,
			register: $run->register,
			schema: $run->caseSchema,
			filters: ['assignee' => $absentee]
		);
	}//end casesAssignedTo()

	/**
	 * Whether a case of the absentee is routed: in scope, not final, and not routed already.
	 *
	 * A routed case is marked as seen.
	 *
	 * @param SubstitutionWorkRun  $run  The gathering, with the substitution in hand.
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool Whether it is routed.
	 */
	private function caseRoutes(SubstitutionWorkRun $run, array $case): bool {
		$scope = (string)($run->sub['scope'] ?? 'all');
		$scopeRefs = array_map('strval', (array)($run->sub['scopeRefs'] ?? []));
		if ($this->caseInScope(case: $case, scope: $scope, scopeRefs: $scopeRefs) === false) {
			return false;
		}

		if (in_array((string)($case['status'] ?? ''), $run->finalIds, true) === true) {
			return false;
		}

		return self::claim(seen: $run->seenCases, item: $case);
	}//end caseRoutes()

	/**
	 * Claim an item by its id: true the first time, false for an item without id or already claimed.
	 *
	 * @param array<string, bool>  $seen The ids claimed so far, updated in place.
	 * @param array<string, mixed> $item The case or task.
	 *
	 * @return bool Whether it was claimed now.
	 */
	private static function claim(array &$seen, array $item): bool {
		$id = (string)($item['id'] ?? ($item['uuid'] ?? ''));
		if ($id === '' || isset($seen[$id]) === true) {
			return false;
		}

		$seen[$id] = true;

		return true;
	}//end claim()

	/**
	 * What a routed item carries about the substitution that routed it.
	 *
	 * @param array<string, mixed> $sub The substitution record.
	 *
	 * @return array{absentee: string, substitutionId: string, until: string} The stamp.
	 */
	private function stampOf(array $sub): array {
		return [
			'absentee' => (string)($sub['absentee'] ?? ''),
			'substitutionId' => (string)($sub['id'] ?? ($sub['uuid'] ?? '')),
			'until' => $this->routedUntil(sub: $sub),
		];
	}//end stampOf()

	/**
	 * Collect the absentee's open tasks for one substitution.
	 *
	 * 🔴 THE TASKS MOVED AND THIS READ DID NOT, which is why it is being
	 * rewritten rather than tidied. `task_schema` was read, found, and
	 * queried, and it returned steadily fewer rows as every writer moved to
	 * the engine, until the substitution screen showed an absentee's cases
	 * with no tasks at all. Nothing failed: the guard `if ($taskSchema !==
	 * '')` was TRUE the whole time, so there was no branch to notice.
	 *
	 * The terminal filter is the engine's now (`openForAssignee` asks for
	 * `isTerminal: false`), so the three state names are gone from here too.
	 *
	 * @param array<string, mixed> $sub  The substitution record.
	 * @param array<string, bool>  $seen Dedup map, mutated in place.
	 *
	 * @return array<int, array<string, mixed>> The tasks.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function collectTasks(array $sub, array &$seen): array {
		$stamp = $this->stampOf(sub: $sub);
		$scope = (string)($sub['scope'] ?? 'all');
		$scopeRefs = array_map('strval', (array)($sub['scopeRefs'] ?? []));

		$collected = [];
		foreach ($this->engineTasks->openForAssignee(actor: $stamp['absentee']) as $task) {
			$outOfScope = ($scope === 'cases' && in_array((string)($task['case'] ?? ''), $scopeRefs, true) === false);
			if ($outOfScope === false && self::claim(seen: $seen, item: $task) === true) {
				$task['_substituted'] = $stamp;
				$collected[] = $task;
			}
		}

		return $collected;
	}//end collectTasks()

	/**
	 * The day this substitution stops routing work, as the marker prints it.
	 *
	 * The typed `endDate`, unless an approved humaniq leave set a different
	 * one: `SubstitutionService` stamps the effective end on `_activeUntil`
	 * when it resolves the substitution as active, so the marker on My work
	 * names the day the work actually goes back rather than a date the leave
	 * has already overtaken.
	 *
	 * @param array<string, mixed> $sub The substitution record.
	 *
	 * @return string The end date (`Y-m-d`), or '' when the record has none.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	private function routedUntil(array $sub): string {
		$effective = (string)($sub['_activeUntil'] ?? '');
		if ($effective !== '') {
			return $effective;
		}

		return (string)($sub['endDate'] ?? '');
	}//end routedUntil()

	/**
	 * Whether a case falls within a substitution scope.
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param string $scope all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs The narrowed refs.
	 *
	 * @return bool True when the case is covered by the scope.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function caseInScope(array $case, string $scope, array $scopeRefs): bool {
		if ($scope === 'all') {
			return true;
		}

		if ($scope === 'caseTypes') {
			return in_array((string)($case['caseType'] ?? ''), $scopeRefs, true);
		}

		if ($scope === 'cases') {
			$id = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			return in_array($id, $scopeRefs, true);
		}

		return false;
	}//end caseInScope()

	/**
	 * Resolve the set of final statusType ids (closed/archived cases).
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register Register id.
	 *
	 * @return array<int, string> The final statusType ids.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function finalStatusIds(object $objectService, string $register): array {
		$statusTypeSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
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
				$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			}
		}

		return array_values(array_filter($ids));
	}//end finalStatusIds()
}//end class
