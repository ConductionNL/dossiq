<?php

/**
 * Dossiq SubstitutionAuditService.
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
use OCA\Dossiq\Kernel\Contracts\SubstitutionAuditServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Appends and queries capacity-stamped activity entries.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionAuditService implements SubstitutionAuditServiceInterface {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config bridge.
	 * @param SubstitutionService $substitutionService Capacity resolution.
	 * @param LoggerInterface $logger The logger.
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
	 * @param string $action A short action label (e.g. "task-completed").
	 *
	 * @return array<string, mixed>|null The stamped entry, or null when own work.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function stampIfSubstituted(string $caseId, string $actorId, string $action): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$hasBlank = in_array('', [$register, $caseSchema, $caseId, $actorId], true);
		if ($objectService === null || $hasBlank === true) {
			return null;
		}

		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
		$entry = $this->entryFor(caseId: $caseId, actorId: $actorId, action: $action, case: $case);
		if ($entry === null) {
			return null;
		}

		$case['activity'] = $this->appendedActivity(case: $case, entry: $entry);

		try {
			$objectService->updateObject(objectId: $caseId, data: $case);
		} catch (\Throwable $e) {
			$this->logger->error('Substitution capacity stamp failed', ['caseId' => $caseId, 'error' => $e->getMessage()]);
			return null;
		}

		return $entry;
	}//end stampIfSubstituted()

	/**
	 * The capacity entry of an action on a stored case, or null for a missing case or own work.
	 *
	 * @param string                    $caseId  The case being mutated.
	 * @param string                    $actorId The acting user id.
	 * @param string                    $action  A short action label.
	 * @param array<string, mixed>|null $case    The stored case, or null.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function entryFor(string $caseId, string $actorId, string $action, ?array $case): ?array {
		if ($case === null) {
			return null;
		}

		$stamp = new CapacityStamp();
		$stamp->caseId = $caseId;
		$stamp->actorId = $actorId;
		$stamp->action = $action;
		$stamp->absentee = (string)($case['assignee'] ?? '');

		return $this->capacityEntry(stamp: $stamp, case: $case);
	}//end entryFor()

	/**
	 * The activity entry of an action taken in a covered absentee's name, or null for own work.
	 *
	 * @param CapacityStamp        $stamp The action.
	 * @param array<string, mixed> $case  The stored case.
	 *
	 * @return array<string, string>|null
	 */
	private function capacityEntry(CapacityStamp $stamp, array $case): ?array {
		if ($stamp->absentee === '' || $stamp->absentee === $stamp->actorId) {
			// Own work (or unassigned) — never capacity-stamped.
			return null;
		}

		$caseType = null;
		if (isset($case['caseType']) === true) {
			$caseType = (string)$case['caseType'];
		}

		$sub = $this->substitutionService->resolveActingCapacity(
			actorId: $stamp->actorId,
			absentee: $stamp->absentee,
			caseId: $stamp->caseId,
			caseType: $caseType
		);
		if ($sub === null) {
			return null;
		}

		return $this->activityEntry(stamp: $stamp, substitution: $sub);
	}//end capacityEntry()

	/**
	 * The substitution-action entry for the case's activity.
	 *
	 * @param CapacityStamp        $stamp        The action.
	 * @param array<string, mixed> $substitution The covering substitution.
	 *
	 * @return array<string, string>
	 */
	private function activityEntry(CapacityStamp $stamp, array $substitution): array {
		return [
			'type' => 'substitution-action',
			'action' => $stamp->action,
			'actor' => $stamp->actorId,
			'actedOnBehalfOf' => $stamp->absentee,
			'substitutionId' => (string)($substitution['id'] ?? ($substitution['uuid'] ?? '')),
			'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];
	}//end activityEntry()

	/**
	 * The case's activity with the entry appended, encoded as stored.
	 *
	 * @param array<string, mixed>  $case  The stored case.
	 * @param array<string, string> $entry The entry.
	 *
	 * @return string|false
	 */
	private function appendedActivity(array $case, array $entry): string|false {
		$activity = $this->decodeActivity(raw: ($case['activity'] ?? null));
		$activity[] = $entry;

		return json_encode($activity);
	}//end appendedActivity()

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
	public function getActionsForSubstitution(string $substitutionId): array {
		if ($substitutionId === '') {
			return [];
		}

		$store = $this->substitutionStore();
		if ($store === null) {
			return [];
		}

		$absentee = $this->absenteeOf(store: $store, substitutionId: $substitutionId);
		if ($absentee === '') {
			return [];
		}

		return $this->actionsOn(cases: $this->absenteeCases(store: $store, absentee: $absentee), substitutionId: $substitutionId);
	}//end getActionsForSubstitution()

	/**
	 * The object service, register, substitution schema and case schema, or null when any is missing.
	 *
	 * @return array{0: object, 1: string, 2: string, 3: string}|null The store.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function substitutionStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$subSchema = (string)$this->settingsService->getConfigValue('substitution_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$hasBlank = in_array('', [$register, $subSchema, $caseSchema], true);
		if ($objectService === null || $hasBlank === true) {
			return null;
		}

		return [$objectService, $register, $subSchema, $caseSchema];
	}//end substitutionStore()

	/**
	 * The absentee of a substitution, or an empty string when it has none or does not resolve.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $store          The store.
	 * @param string                                            $substitutionId The substitution UUID.
	 *
	 * @return string The absentee.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function absenteeOf(array $store, string $substitutionId): string {
		[$objectService, $register, $subSchema] = $store;
		$sub = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $subSchema, id: $substitutionId);
		if ($sub === null) {
			return '';
		}

		return (string)($sub['absentee'] ?? '');
	}//end absenteeOf()

	/**
	 * The cases assigned to the absentee.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $store    The store.
	 * @param string                                            $absentee The absentee.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	private function absenteeCases(array $store, string $absentee): array {
		[$objectService, $register, , $caseSchema] = $store;

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			filters: ['assignee' => $absentee]
		);
	}//end absenteeCases()

	/**
	 * The substitution's actions recorded on the cases, oldest first.
	 *
	 * @param array<int, array<string, mixed>> $cases          The absentee's cases.
	 * @param string                           $substitutionId The substitution.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function actionsOn(array $cases, string $substitutionId): array {
		$actions = [];
		foreach ($cases as $case) {
			$actions = array_merge(
				$actions,
				$this->collectSubstitutionEntries(
					caseData: $case,
					substitutionId: $substitutionId
				)
			);
		}//end foreach

		usort(
			$actions,
			static function (array $a, array $b): int {
				return strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''));
			}
		);

		return $actions;
	}//end actionsOn()

	/**
	 * Collect the activity entries on a case that carry a substitution id.
	 *
	 * @param array<string, mixed> $caseData The case record.
	 * @param string $substitutionId The substitution UUID.
	 *
	 * @return array<int, array<string, mixed>> The matching, case-tagged entries.
	 */
	private function collectSubstitutionEntries(array $caseData, string $substitutionId): array {
		$caseId = (string)($caseData['id'] ?? ($caseData['uuid'] ?? ''));
		$entries = [];
		foreach ($this->decodeActivity(raw: ($caseData['activity'] ?? null)) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ((string)($entry['substitutionId'] ?? '') !== $substitutionId) {
				continue;
			}

			$entry['caseId'] = $caseId;
			$entry['caseTitle'] = (string)($caseData['title'] ?? '');
			$entries[] = $entry;
		}//end foreach

		return $entries;
	}//end collectSubstitutionEntries()

	/**
	 * Decode the case activity JSON string into an array of entries.
	 *
	 * @param mixed $raw The raw activity property (JSON string or array).
	 *
	 * @return array<int, mixed>
	 */
	private function decodeActivity(mixed $raw): array {
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
