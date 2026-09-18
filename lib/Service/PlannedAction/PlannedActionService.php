<?php

/**
 * Reads and writes the planned actions on a case (gap register row 3.28).
 *
 * The decision about what follows what is {@see PlannedActionChain}'s and is
 * deliberately not here. What is here is the reading and the writing: the
 * action types this case type declares, the action currently planned, and the
 * two writes that completing one performs.
 *
 * WHY THE SLUGS ARE RESOLVED FROM CONFIG WITH A FALLBACK TO THE SCHEMA SLUG.
 * `planned_action_schema` is not seeded into the app-config of an instance that
 * imported this register before the fragment existed, and OpenRegister resolves
 * a schema by its canonical slug as well as by its numeric id. Without the
 * fallback the feature would be silently inert on exactly the instances that
 * have been running longest, which is the failure `store.js` already documents
 * for `caseType` and `statusType`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\PlannedAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\PlannedAction;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The planned actions of a case.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */
class PlannedActionService {

	use SearchesObjects;

	/**
	 * The config key holding the plannedAction schema, and its canonical slug.
	 *
	 * @var string
	 */
	public const ACTION_SCHEMA_KEY = 'planned_action_schema';

	/**
	 * The config key holding the plannedActionType schema.
	 *
	 * @var string
	 */
	public const TYPE_SCHEMA_KEY = 'planned_action_type_schema';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param PlannedActionChain $chain Decides what follows a completed action.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PlannedActionChain $chain,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action planned on this case, or null when none is.
	 *
	 * The EARLIEST planned one, because "what happens next on this case" has
	 * one answer and a list of three is not it. Actions are scheduled one at a
	 * time, so more than one open action means a chain was branched by hand,
	 * and the nearest is still the next thing to do.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The next planned action, or null.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function nextFor(string $caseId): ?array {
		$planned = $this->plannedOn(caseId: $caseId);
		if (count($planned) === 0) {
			return null;
		}

		usort(
			$planned,
			static fn (array $a, array $b): int => (
				strcmp((string)($a['plannedFor'] ?? ''), (string)($b['plannedFor'] ?? ''))
			)
		);

		return $planned[0];
	}//end nextFor()

	/**
	 * Every action still planned on this case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The planned actions.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function plannedOn(string $caseId): array {
		if ($caseId === '') {
			return [];
		}

		$rows = $this->readActions(filters: ['case' => $caseId, '_limit' => 100]);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => ((string)($row['state'] ?? 'planned') === 'planned')
			)
		);
	}//end plannedOn()

	/**
	 * The actions planned for one person, across every case.
	 *
	 * The queue's read. Filtered on `owner` server-side and on the state here,
	 * for the same reason {@see self::plannedOn()} does it: `state` is an enum
	 * with three values and only one of them is waiting on anybody, and a
	 * queue that listed the completed ones would be a list nobody trusts by
	 * the end of the week.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, array<string, mixed>> The actions they own, earliest first.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function ownedBy(string $userId): array {
		if ($userId === '') {
			return [];
		}

		$rows = array_values(
			array_filter(
				$this->readActions(filters: ['owner' => $userId, '_limit' => 100]),
				static fn (array $row): bool => (
					(string)($row['state'] ?? 'planned') === 'planned'
					&& (string)($row['owner'] ?? '') === $userId
				)
			)
		);

		// The owner is re-checked HERE and not trusted from the filter alone.
		// OpenRegister drops a filter on a key the schema does not declare and
		// answers the whole table, so a rename of `owner` would turn this
		// person's queue into everybody's without a single error anywhere.
		usort(
			$rows,
			static fn (array $a, array $b): int => (
				strcmp((string)($a['plannedFor'] ?? ''), (string)($b['plannedFor'] ?? ''))
			)
		);

		return $rows;
	}//end ownedBy()

	/**
	 * Complete an action, and plan the one its type declares as the successor.
	 *
	 * Returns the action that was planned, or null when the chain ends here.
	 * A caller cannot tell "the chain ended" from "nothing happened" by looking
	 * at the case, so the two are distinguished in the return rather than left
	 * to be inferred.
	 *
	 * @param array<string, mixed> $action The action being completed.
	 * @param string $completedBy Who completed it.
	 * @param array<string, string> $roleHolders Who holds each role on this case.
	 * @param DateTimeImmutable|null $completedOn The completion date; today when null.
	 *
	 * @return array<string, mixed>|null The action planned next, or null.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function complete(
		array $action,
		string $completedBy = '',
		array $roleHolders = [],
		?DateTimeImmutable $completedOn = null,
	): ?array {
		$on = ($completedOn ?? new DateTimeImmutable('today'));
		$actionId = (string)($action['id'] ?? ($action['uuid'] ?? ''));

		$this->write(
			object: array_merge(
				$action,
				[
					'state' => 'completed',
					'completedAt' => $on->format('Y-m-d\TH:i:sP'),
					'completedBy' => $completedBy,
				]
			),
			uuid: ($actionId === '' ? null : $actionId)
		);

		$next = $this->chain->next(
			completed: $action,
			types: $this->typesFor(caseTypeId: (string)($action['caseType'] ?? '')),
			completedOn: $on,
			roleHolders: $roleHolders
		);

		if ($next === null) {
			return null;
		}

		return $this->write(object: $next, uuid: null);
	}//end complete()

	/**
	 * The action types available on a case type, by identifier.
	 *
	 * A type naming no case type is offered on every one of them, so the read
	 * is unfiltered and the narrowing happens here. Filtering on `caseType`
	 * server-side would silently drop exactly the organisation-wide types.
	 *
	 * @param string $caseTypeId The case type UUID, or '' for every type.
	 *
	 * @return array<string, array<string, mixed>> The types, by identifier.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function typesFor(string $caseTypeId): array {
		$rows = $this->readTypes();

		$out = [];
		foreach ($rows as $row) {
			$identifier = (string)($row['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$declaredFor = (string)($row['caseType'] ?? '');
			if ($declaredFor !== '' && $caseTypeId !== '' && $declaredFor !== $caseTypeId) {
				continue;
			}

			$out[$identifier] = $row;
		}

		return $out;
	}//end typesFor()

	/**
	 * Read planned actions through OpenRegister.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 */
	private function readActions(array $filters): array {
		return $this->read(schemaKey: self::ACTION_SCHEMA_KEY, slug: 'plannedAction', filters: $filters);
	}

	/**
	 * Read action types through OpenRegister.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 */
	private function readTypes(): array {
		return $this->read(
			schemaKey: self::TYPE_SCHEMA_KEY,
			slug: 'plannedActionType',
			filters: ['_limit' => 200]
		);
	}

	/**
	 * One read, fail-soft.
	 *
	 * An unreadable register answers an EMPTY LIST and logs, rather than
	 * throwing: a case page that cannot show its next action is worse than one
	 * that shows none, and this is a planning aid rather than an access
	 * decision. `CaseAccessGuard` answers the opposite way, on purpose.
	 *
	 * @param string $schemaKey The config key naming the schema.
	 * @param string $slug The canonical schema slug, used when the key is blank.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(string $schemaKey, string $slug, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return [];
		}

		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($schema === '') {
			$schema = $slug;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq PlannedActionService: could not read ' . $slug . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}

	/**
	 * Write one planned action.
	 *
	 * @param array<string, mixed> $object The action.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return array<string, mixed>|null The saved action, or null when the write failed.
	 */
	private function write(array $object, ?string $uuid): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return null;
		}

		$schema = (string)$this->settingsService->getConfigValue(self::ACTION_SCHEMA_KEY);
		if ($schema === '') {
			$schema = 'plannedAction';
		}

		try {
			return $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object,
				uuid: $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq PlannedActionService: could not write a planned action: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return null;
		}
	}
}//end class
