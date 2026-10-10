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
use OCA\Dossiq\Service\CaseType\OpenCaseCounts;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TranslatedText;
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
	 * @param TranslatedText $text A translatable case type title in the reader's language.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly TranslatedText $text,
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
	 * @spec openspec/changes/menu-case-type-titles-in-the-readers-language/specs/case-type-navigation/spec.md
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
				$this->rememberSuperseded(row: $row);
				continue;
			}

			$uuid = $this->resolveUuid(object: $row);
			if ($uuid === null || isset($caseTypes[$uuid]) === true) {
				continue;
			}

			// The title is declared translatable, so a row carries it as a
			// language map. A (string) cast of that map is "Array", which is
			// what every case type in the picker, the chosen list and the
			// menu was called (finding B2, 10 Oct).
			$title = $this->text->forReader(value: ($row['title'] ?? ''));
			if ($title === '') {
				$title = $uuid;
			}

			$caseTypes[$uuid] = [
				'id' => $uuid,
				'title' => $title,
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
	 * list holds. When the counts are unknown (no OpenRegister, no case
	 * schema, no `caseType` facet in the answer) every `openCases` is null, so
	 * the picker shows no number rather than a 0 nobody counted. A facet query
	 * that throws is passed on to the caller.
	 *
	 * Call it after offeredCaseTypes() or visibleCaseTypes(): those read the
	 * case type versions this folds by.
	 *
	 * @param array<int, array{id: string, title: string}> $caseTypes The case types to count.
	 *
	 * @return array<int, array{id: string, title: string, openCases: int|null}> The case types with their counts.
	 *
	 * @throws \Throwable When OpenRegister's facet query fails.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function withOpenCaseCounts(array $caseTypes): array {
		$counts = (new OpenCaseCounts(settingsService: $this->settingsService))->byCaseType(
			supersededBy: $this->supersededBy
		);

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
	 * The same case types, each saying its count is unknown.
	 *
	 * What the controller answers when the count query failed: no number on
	 * screen, never a 0 nobody counted.
	 *
	 * @param array<int, array{id: string, title: string}> $caseTypes The case types.
	 *
	 * @return array<int, array{id: string, title: string, openCases: null}> The case types, count unknown.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function withUnknownOpenCaseCounts(array $caseTypes): array {
		return array_map(
			static function (array $caseType): array {
				$caseType['openCases'] = null;

				return $caseType;
			},
			$caseTypes
		);
	}//end withUnknownOpenCaseCounts()

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
	 * Remember which version replaced a superseded case type version.
	 *
	 * @param array<string, mixed> $row The superseded case type row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	private function rememberSuperseded(array $row): void {
		$old = $this->resolveUuid(object: $row);
		if ($old !== null && is_string($row['supersededBy']) === true) {
			$this->supersededBy[$old] = $row['supersededBy'];
		}
	}//end rememberSuperseded()

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
