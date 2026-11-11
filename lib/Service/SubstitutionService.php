<?php

/**
 * Dossiq SubstitutionService.
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
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use JsonSerializable;
use OCA\Dossiq\Service\Substitution\HumaniqLeaveReader;
use OCA\Dossiq\Service\Substitution\SubstitutedWorkResolver;
use OCA\Dossiq\Service\Substitution\SubstitutionValidator;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves vervanging/waarneming substitutions and the workload they route.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionService {
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
	 * @param LoggerInterface $logger The logger.
	 * @param SubstitutionValidator $validator Create-input validation + overlap detection.
	 * @param SubstitutedWorkResolver $workResolver Resolver for the work a substitution routes.
	 * @param HumaniqLeaveReader $leaveReader Reads humaniq's approved leave for the absentee.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly SubstitutionValidator $validator,
		private readonly SubstitutedWorkResolver $workResolver,
		private readonly HumaniqLeaveReader $leaveReader,
	) {
	}//end __construct()

	/**
	 * Create a substitution after validation.
	 *
	 * Rejects self-substitution, missing/invalid period, and a same-period
	 * overlapping full-scope substitution for the same absentee. A disjoint
	 * scope for the same period is accepted.
	 *
	 * @param string $absentee Handler being covered (user id).
	 * @param string $substitute Waarnemer (user id).
	 * @param string $startDate Inclusive start (Y-m-d).
	 * @param string $endDate Inclusive end (Y-m-d), required.
	 * @param string $scope One of all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs caseType/case UUIDs when narrowed.
	 * @param string $reason One of verlof|ziekte|anders.
	 * @param string $createdBy Creating user id (self or coordinator).
	 * @param string $comment Optional free-text comment.
	 *
	 * @return array<string, mixed> The created substitution object.
	 *
	 * @throws \InvalidArgumentException On any validation failure.
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function create(
		string $absentee,
		string $substitute,
		string $startDate,
		string $endDate,
		string $scope = 'all',
		array $scopeRefs = [],
		string $reason = 'verlof',
		string $createdBy = '',
		string $comment = '',
	): array {
		$request = new SubstitutionRequest();
		$request->absentee = trim($absentee);
		$request->substitute = trim($substitute);
		$request->startDate = $startDate;
		$request->endDate = $endDate;
		$request->scope = $scope;
		$request->scopeRefs = $scopeRefs;
		$request->reason = $reason;
		$request->createdBy = $createdBy;
		$request->comment = $comment;

		$row = $this->substitutionRow(request: $request, period: $this->validatedPeriod(request: $request));
		$saved = $this->saveSubstitution(row: $row);
		$this->activeCache = [];

		return ($saved ?? $row);
	}//end create()

	/**
	 * Validate the request and refuse a same-period overlapping full-scope substitution.
	 *
	 * @param SubstitutionRequest $request The substitution to create.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} The inclusive start and end.
	 *
	 * @throws \InvalidArgumentException On any validation failure.
	 */
	private function validatedPeriod(SubstitutionRequest $request): array {
		[$start, $end] = $this->validator->validateCreate(request: $request);

		$this->validator->assertNoOverlappingFullScope(
			absentee: $request->absentee,
			scope: $request->scope,
			start: $start,
			end: $end
		);

		return [$start, $end];
	}//end validatedPeriod()

	/**
	 * The stored row of a validated substitution; the absentee created it unless somebody else is named.
	 *
	 * @param SubstitutionRequest                               $request The substitution to create.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $period  The validated start and end.
	 *
	 * @return array<string, mixed>
	 */
	private function substitutionRow(SubstitutionRequest $request, array $period): array {
		$createdByValue = $request->absentee;
		if ($request->createdBy !== '') {
			$createdByValue = $request->createdBy;
		}

		return [
			'absentee' => $request->absentee,
			'substitute' => $request->substitute,
			'startDate' => $period[0]->format('Y-m-d'),
			'endDate' => $period[1]->format('Y-m-d'),
			'scope' => $request->scope,
			'scopeRefs' => array_values($request->scopeRefs),
			'reason' => $request->reason,
			'comment' => $request->comment,
			'status' => 'active',
			'createdBy' => $createdByValue,
		];
	}//end substitutionRow()

	/**
	 * Save a substitution row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>|null The saved object.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 */
	private function saveSubstitution(array $row): ?array {
		[$objectService, $register, $schema] = $this->requireContext();

		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			object: $row
		);
	}//end saveSubstitution()

	/**
	 * Revoke a substitution immediately (status -> revoked).
	 *
	 * @param string $id The substitution UUID.
	 *
	 * @return array<string, mixed>|null The updated object, or null when not found.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function revoke(string $id): ?array {
		[$objectService, $register, $schema] = $this->requireContext();
		$existing = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		if ($existing === null) {
			return null;
		}

		$existing['status'] = 'revoked';
		$saved = $objectService->updateObject(objectId: $id, data: $existing);
		$this->activeCache = [];

		if ($saved instanceof JsonSerializable === true) {
			$serialized = $saved->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return $existing;
	}//end revoke()

	/**
	 * Resolve the substitutions that are active for a substitute on a date.
	 *
	 * A substitution is active when status == active AND the reference date
	 * falls inside its period. The period is the absentee's approved humaniq
	 * leave when one covers that day, and the record's own typed dates
	 * otherwise ({@see self::activeRowOn()}). Records whose typed endDate has
	 * passed with no leave behind it are lazily marked `ended` (best-effort
	 * persistence) and excluded. Results are cached per request.
	 *
	 * Each returned record carries `_activeUntil`: the day the routing stops,
	 * which is what My work prints on the marker.
	 *
	 * @param string $userId The waarnemer (substitute) user id.
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array<int, array<string, mixed>> The active substitution records.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getActiveSubstitutionsFor(string $userId, ?DateTimeImmutable $date = null): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$ref = ($date ?? new DateTimeImmutable('today'));
		$refDay = $ref->format('Y-m-d');
		$cacheKey = $userId . '|' . $refDay;
		if (isset($this->activeCache[$cacheKey]) === true) {
			return $this->activeCache[$cacheKey];
		}

		$active = $this->activeRowsFor(userId: $userId, refDay: $refDay);
		if ($active === null) {
			return [];
		}

		$this->activeCache[$cacheKey] = $active;

		return $active;
	}//end getActiveSubstitutionsFor()

	/**
	 * The user's substitutions as they apply on the reference day, or null without a store.
	 *
	 * @param string $userId The substitute (user id), trimmed.
	 * @param string $refDay Reference day (Y-m-d).
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function activeRowsFor(string $userId, string $refDay): ?array {
		[$objectService, $register, $schema] = $this->resolveContext();
		if ($objectService === null) {
			return null;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['substitute' => $userId]
		);

		$active = [];
		foreach ($rows as $row) {
			$resolved = $this->activeRowOn(
				row: $row,
				refDay: $refDay,
				objectService: $objectService
			);
			if ($resolved !== null) {
				$active[] = $resolved;
			}
		}

		return $active;
	}//end activeRowsFor()

	/**
	 * One substitution row as it applies on the reference day, or null.
	 *
	 * The period is the absentee's approved humaniq leave when there is one
	 * covering the day, and the row's own typed dates otherwise. Statutory
	 * terms do not pause for leave, so a substitution that was typed to end
	 * before the leave did would hand the work back to somebody who is still
	 * away, and the cases would sit unseen until they breached.
	 *
	 * A row that applies is returned with `_activeUntil` stamped: the day the
	 * routing actually stops, which the My work marker prints.
	 *
	 * Applies the lazy-expiry side effect: a row past its typed end with no
	 * leave to extend it is best-effort persisted as `ended` and excluded.
	 *
	 * @param array<string, mixed> $row The substitution row.
	 * @param string $refDay Reference day (Y-m-d).
	 * @param object $objectService The ObjectService.
	 *
	 * @return array<string, mixed>|null The applicable row, or null.
	 *
	 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
	 */
	private function activeRowOn(
		array $row,
		string $refDay,
		object $objectService,
	): ?array {
		$status = (string)($row['status'] ?? '');
		// `revoked` and the lazily persisted `ended` both land here: a
		// substitution that is not active routes nothing, and leave does not
		// revive one somebody deliberately ended.
		if ($status !== 'active') {
			return null;
		}

		// The leave, when humaniq knows of one, sets the period outright. It is
		// read before the typed dates are applied, because the case it exists
		// for is exactly the one the typed dates would have excluded.
		$leave = $this->leaveReader->approvedLeaveCovering(
			userId: (string)($row['absentee'] ?? ''),
			day: $refDay
		);
		if ($leave !== null) {
			$row['_activeUntil'] = $leave['endDate'];

			return $row;
		}

		// Lazy expiry: past endDate and no leave behind it -> ended, excluded.
		$end = (string)($row['endDate'] ?? '');
		if ($end !== '' && $refDay > $end) {
			$this->markEnded(
				objectService: $objectService,
				row: $row
			);

			return null;
		}

		return $this->startedRow(row: $row, refDay: $refDay);
	}//end activeRowOn()

	/**
	 * The row with its typed end as `_activeUntil`, or null before its typed start.
	 *
	 * @param array<string, mixed> $row    The substitution row, not past its typed end.
	 * @param string               $refDay Reference day (Y-m-d).
	 *
	 * @return array<string, mixed>|null
	 */
	private function startedRow(array $row, string $refDay): ?array {
		$start = (string)($row['startDate'] ?? '');
		if ($start !== '' && $refDay < $start) {
			return null;
		}

		$row['_activeUntil'] = (string)($row['endDate'] ?? '');

		return $row;
	}//end startedRow()

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
	 * @param string $userId The waarnemer (substitute) user id.
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getSubstitutedWorkFor(string $userId, ?DateTimeImmutable $date = null): array {
		return $this->workResolver->resolve(
			subs: $this->getActiveSubstitutionsFor(userId: $userId, date: $date)
		);
	}//end getSubstitutedWorkFor()

	/**
	 * Resolve the active substitution under which the given user may act on an
	 * item assigned to a different absentee — used for capacity stamping.
	 *
	 * Returns the matching substitution (so the caller can stamp
	 * actedOnBehalfOf + substitutionId) or null when the user is acting on
	 * their own work / no active substitution covers the item.
	 *
	 * @param string $actorId The acting user id.
	 * @param string $absentee The item's current assignee.
	 * @param string $caseId The case id (for scope checks).
	 * @param string|null $caseType The case's caseType (for scope checks).
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array<string, mixed>|null The covering substitution, or null.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function resolveActingCapacity(
		string $actorId,
		string $absentee,
		string $caseId = '',
		?string $caseType = null,
		?DateTimeImmutable $date = null,
	): ?array {
		if ($this->actsForSomebodyElse(actorId: $actorId, absentee: $absentee) === false) {
			return null;
		}

		$covering = array_filter(
			$this->getActiveSubstitutionsFor(userId: $actorId, date: $date),
			static fn (array $sub): bool => (string)($sub['absentee'] ?? '') === $absentee
		);

		return $this->firstCovering(substitutions: $covering, probe: ['caseType' => $caseType, 'id' => $caseId]);
	}//end resolveActingCapacity()

	/**
	 * Whether an actor acts on the work of another, named person.
	 *
	 * @param string $actorId  The acting user id.
	 * @param string $absentee The item's current assignee.
	 *
	 * @return bool
	 */
	private function actsForSomebodyElse(string $actorId, string $absentee): bool {
		return $actorId !== '' && $absentee !== '' && $actorId !== $absentee;
	}//end actsForSomebodyElse()

	/**
	 * The first substitution whose scope covers the item.
	 *
	 * @param array<int, array<string, mixed>>         $substitutions The actor's active substitutions for the absentee.
	 * @param array{caseType: string|null, id: string} $probe         The item: its case type and case id.
	 *
	 * @return array<string, mixed>|null The covering substitution, or null.
	 */
	private function firstCovering(array $substitutions, array $probe): ?array {
		foreach ($substitutions as $sub) {
			$scope = (string)($sub['scope'] ?? 'all');
			$scopeRefs = array_map('strval', (array)($sub['scopeRefs'] ?? []));
			if ($this->workResolver->caseInScope(case: $probe, scope: $scope, scopeRefs: $scopeRefs) === true) {
				return $sub;
			}
		}

		return null;
	}//end firstCovering()

	/**
	 * Best-effort lazy persistence of the `ended` status.
	 *
	 * @param object $objectService The ObjectService.
	 * @param array<string, mixed> $row The expired substitution row.
	 *
	 * @return void
	 */
	private function markEnded(object $objectService, array $row): void {
		$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '') {
			return;
		}

		try {
			$row['status'] = 'ended';
			$objectService->updateObject(objectId: $id, data: $row);
		} catch (\Throwable $e) {
			$this->logger->warning('Substitution lazy-ended persistence failed', ['id' => $id, 'error' => $e->getMessage()]);
		}
	}//end markEnded()

	/**
	 * Resolve the ObjectService + register + substitution schema context,
	 * tolerating a missing/unconfigured OpenRegister (the caller decides).
	 *
	 * @return array{0: object|null, 1: string, 2: string}
	 */
	private function resolveContext(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('substitution_schema');

		return [$objectService, $register, $schema];
	}//end resolveContext()

	/**
	 * Resolve the context, insisting every piece is present.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the substitution schema is unavailable.
	 */
	private function requireContext(): array {
		[$objectService, $register, $schema] = $this->resolveContext();
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('OpenRegister is not available or the substitution schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end requireContext()
}//end class
