<?php

/**
 * Dossiq Menu Case Types Service.
 *
 * The case types each user chose for the "My case types" heading of the
 * sidebar, in their own order (board DqPersoonlijkeInstellingen). The choice
 * is an IConfig user value holding a JSON array of case type uuids; every read
 * intersects it with the case types offered to the user right now: the ones
 * their team handles (the case type's handling teams, REQ-CT-44), or every
 * case type they may see when they are in no handling team. So a retired, no
 * longer visible or no longer handled case type drops out of the menu without
 * a write.
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
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Reads and writes the per-user list of case types in the menu.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */
class MenuCaseTypesService {

	use SearchesObjects;

	/**
	 * The IConfig user value key holding the chosen uuids.
	 *
	 * @var string
	 */
	public const USER_KEY = 'menu_case_types';

	/**
	 * The seeded Woo request case type (lib/Settings/register.d/81-woo-verzoek.json),
	 * the one case type the menu named before users could choose.
	 *
	 * @var string
	 */
	public const DEFAULT_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * What an open case is, the same population the dashboard counts.
	 *
	 * Not at a final status, not at a status hidden from lists, not a draft:
	 * `KpiAggregationService::OPEN_WORK`. 0 and not `false`, for the reason
	 * that constant gives: a PHP bool does not compare against stored JSON.
	 *
	 * @var array<string, int>
	 */
	public const OPEN_WORK = ['isFinalStatus' => 0, 'statusHiddenInLists' => 0, 'isDraft' => 0];

	/**
	 * The longest list a user may keep.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 30;

	/**
	 * Each superseded case type version, pointing at the version that replaced it.
	 *
	 * Filled by the last read of the case types, so a count can fold a case
	 * opened under an old version into the version the menu lists.
	 *
	 * @var array<string, string>
	 */
	private array $supersededBy = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register, schema, ObjectService).
	 * @param IConfig $config Nextcloud config (user values).
	 * @param IGroupManager $groupManager Nextcloud groups, the one authority on who is in a team.
	 * @param IUserManager $userManager Nextcloud users.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The case types offered to this user for their menu, sorted by title.
	 *
	 * The visible case types whose handling teams include a Nextcloud group
	 * the user is in. When that is none, because the user is in no handling
	 * team, every visible case type: a new employee or an instance that has
	 * not named its teams yet gets a useful picker, not an empty one.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array{id: string, title: string}> The offered case types.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
	 */
	public function offeredCaseTypes(string $userId): array {
		$rows = $this->currentCaseTypes();

		$userGroups = [];
		$user = $this->userManager->get($userId);
		if ($user !== null) {
			$userGroups = array_flip($this->groupManager->getUserGroupIds($user));
		}

		$handled = [];
		foreach ($rows as $row) {
			foreach ($row['teams'] as $team) {
				if (isset($userGroups[$team]) === true) {
					$handled[] = $row;
					break;
				}
			}
		}

		if (count($handled) === 0) {
			$handled = $rows;
		}

		return array_map(
			static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title']],
			$handled
		);
	}//end offeredCaseTypes()

	/**
	 * The current case types the current user may see, sorted by title.
	 *
	 * OpenRegister applies RBAC under the user session. A superseded version is
	 * left out: the list offers one row per case type, not one per version.
	 *
	 * @return array<int, array{id: string, title: string}> The case types; empty when
	 *   OpenRegister is unavailable or the register or schema is unconfigured.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function visibleCaseTypes(): array {
		return array_map(
			static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title']],
			$this->currentCaseTypes()
		);
	}//end visibleCaseTypes()

	/**
	 * The current visible case types with their handling teams, sorted by title.
	 *
	 * @return array<int, array{id: string, title: string, teams: array<int, string>}> The case types.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
	 */
	private function currentCaseTypes(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || empty($register) === true || empty($schema) === true) {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 500]
		);

		$handling = new CaseTypeHandling();

		$caseTypes = [];
		$this->supersededBy = [];
		foreach ($rows as $row) {
			if (empty($row['supersededBy']) === false) {
				$old = $this->resolveUuid(object: $row);
				if ($old !== null && is_string($row['supersededBy']) === true) {
					$this->supersededBy[$old] = $row['supersededBy'];
				}

				continue;
			}

			$uuid = $this->resolveUuid(object: $row);
			if ($uuid === null || isset($caseTypes[$uuid]) === true) {
				continue;
			}

			$caseTypes[$uuid] = [
				'id' => $uuid,
				'title' => (string)($row['title'] ?? $uuid),
				'teams' => $handling->teams(caseType: $row),
			];
		}

		$list = array_values($caseTypes);
		usort(
			$list,
			static function (array $left, array $right): int {
				return strcasecmp($left['title'], $right['title']);
			}
		);

		return $list;
	}//end currentCaseTypes()

	/**
	 * The same case types, each with the number of open cases of that type the user may see.
	 *
	 * ONE aggregate query, never a count per case type: a terms facet on the
	 * case's `caseType` over the open cases, under the user's own RBAC. A case
	 * opened under an older version of a case type counts for the version the
	 * list holds. When the facet cannot be read every `openCases` is null, so
	 * the picker shows no number rather than a 0 nobody counted.
	 *
	 * Call it after offeredCaseTypes() or visibleCaseTypes(): those read the
	 * case type versions this folds by.
	 *
	 * @param array<int, array{id: string, title: string}> $caseTypes The case types to count.
	 *
	 * @return array<int, array{id: string, title: string, openCases: int|null}> The case types with their counts.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function withOpenCaseCounts(array $caseTypes): array {
		$counts = $this->openCaseCounts();

		return array_map(
			static function (array $caseType) use ($counts): array {
				$caseType['openCases'] = null;
				if ($counts !== null) {
					$caseType['openCases'] = ($counts[$caseType['id']] ?? 0);
				}

				return $caseType;
			},
			$caseTypes
		);
	}//end withOpenCaseCounts()

	/**
	 * Open cases per current case type, from one terms facet.
	 *
	 * @return array<string, int>|null Counts keyed by the current version's uuid, or null when unknown.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	private function openCaseCounts(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === ''
			|| method_exists($objectService, 'getFacetsForObjects') === false
		) {
			return null;
		}

		$query = self::OPEN_WORK;
		$query['@self'] = [
			'register' => $this->idOrSlug(value: $register),
			'schema' => $this->idOrSlug(value: $schema),
		];
		$query['_facets'] = ['caseType' => ['type' => 'terms']];

		try {
			$answer = $objectService->getFacetsForObjects($query);
		} catch (\Throwable) {
			return null;
		}

		$facet = ($answer['facets']['caseType'] ?? null);
		if (is_array($facet) === false) {
			return null;
		}

		$buckets = ($facet['data']['buckets'] ?? ($facet['buckets'] ?? []));
		$counts = [];
		foreach ((array)$buckets as $bucket) {
			$key = ($bucket['key'] ?? ($bucket['value'] ?? null));
			if (is_string($key) === false || $key === '') {
				continue;
			}

			$current = $this->currentVersionOf(uuid: $key);
			$counts[$current] = (($counts[$current] ?? 0) + (int)($bucket['results'] ?? ($bucket['count'] ?? 0)));
		}

		return $counts;
	}//end openCaseCounts()

	/**
	 * The version in use for a case type version, following `supersededBy`.
	 *
	 * Bounded, so a cycle in the data cannot hang the request.
	 *
	 * @param string $uuid A case type version uuid.
	 *
	 * @return string The uuid of the version that is not superseded.
	 */
	private function currentVersionOf(string $uuid): string {
		$seen = [];
		while (isset($this->supersededBy[$uuid]) === true && isset($seen[$uuid]) === false && count($seen) < 50) {
			$seen[$uuid] = true;
			$uuid = $this->supersededBy[$uuid];
		}

		return $uuid;
	}//end currentVersionOf()

	/**
	 * A configured register or schema as OpenRegister's metadata filter wants it.
	 *
	 * @param string $value The configured id or slug.
	 *
	 * @return int|string The numeric id as an int, a slug as it is.
	 */
	private function idOrSlug(string $value): int|string {
		if (ctype_digit($value) === true) {
			return (int)$value;
		}

		return $value;
	}//end idOrSlug()

	/**
	 * The user's chosen case types, in their order, limited to what they may see.
	 *
	 * @param string $userId The user.
	 * @param array<int, array{id: string, title: string}> $visible The result of offeredCaseTypes().
	 *
	 * @return array<int, array{id: string, title: string}> The chosen case types in menu order.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function chosen(string $userId, array $visible): array {
		$stored = $this->config->getUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::USER_KEY,
			default: ''
		);

		// Never chose: the Woo request case type, when the user may see it.
		$ids = [self::DEFAULT_CASE_TYPE];
		if ($stored !== '') {
			$decoded = json_decode(json: $stored, associative: true);
			$ids = [];
			if (is_array($decoded) === true) {
				$ids = $decoded;
			}
		}

		return $this->keepVisible(ids: $ids, visible: $visible);
	}//end chosen()

	/**
	 * Store a new list for the user, cleaned against what they may see.
	 *
	 * @param string $userId The user.
	 * @param array<int, mixed> $ids The uuids in menu order, as sent.
	 * @param array<int, array{id: string, title: string}> $visible The result of offeredCaseTypes().
	 *
	 * @return array<int, array{id: string, title: string}> The list as stored.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function save(string $userId, array $ids, array $visible): array {
		$kept = $this->keepVisible(ids: $ids, visible: $visible);

		$this->config->setUserValue(
			userId: $userId,
			appName: Application::APP_ID,
			key: self::USER_KEY,
			value: (string)json_encode(array_column($kept, 'id'))
		);

		return $kept;
	}//end save()

	/**
	 * Keep the ids that name a visible case type, first occurrence only, up to the cap.
	 *
	 * @param array<int, mixed> $ids The ids in order.
	 * @param array<int, array{id: string, title: string}> $visible The visible case types.
	 *
	 * @return array<int, array{id: string, title: string}> The kept case types in order.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	private function keepVisible(array $ids, array $visible): array {
		$byId = array_column($visible, null, 'id');

		$kept = [];
		foreach ($ids as $id) {
			if (is_string($id) === false || isset($byId[$id]) === false || isset($kept[$id]) === true) {
				continue;
			}

			$kept[$id] = $byId[$id];
			if (count($kept) === self::MAX_ENTRIES) {
				break;
			}
		}

		return array_values($kept);
	}//end keepVisible()

	/**
	 * Resolve an OpenRegister object's UUID from its array shape.
	 *
	 * @param array<string, mixed> $object The object as an associative array.
	 *
	 * @return string|null The UUID, or null when none is resolvable.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	private function resolveUuid(array $object): ?string {
		$self = ($object['@self'] ?? null);

		$candidates = [
			($object['uuid'] ?? null),
			($object['id'] ?? null),
		];

		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}

			if (is_int($candidate) === true) {
				return (string)$candidate;
			}
		}

		return null;
	}//end resolveUuid()
}//end class
