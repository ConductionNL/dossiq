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
 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseType\SupersededCaseTypes;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Reads and writes the per-user list of case types in the menu.
 *
 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
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
	 * A case type as the menu and its picker see it: id and title, and its
	 * icon when it names one.
	 *
	 * @param array{id: string, title: string, icon: string} $row The case type.
	 *
	 * @return array{id: string, title: string, icon?: string} The row.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
	 */
	private static function publicRow(array $row): array {
		$public = ['id' => $row['id'], 'title' => $row['title']];
		if ($row['icon'] !== '') {
			$public['icon'] = $row['icon'];
		}

		return $public;
	}//end publicRow()

	/**
	 * The icon a case type row names (`caseType.icon`), or '' when it names none.
	 *
	 * @param array<string, mixed> $row The case type row.
	 *
	 * @return string The icon name.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
	 */
	private function iconOf(array $row): string {
		$icon = ($row['icon'] ?? '');
		if (is_string($icon) === false) {
			return '';
		}

		return trim($icon);
	}//end iconOf()

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
	 * @return array<int, array{id: string, title: string, icon?: string}> The offered case types.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
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
			static fn (array $row): array => self::publicRow(row: $row),
			$handled
		);
	}//end offeredCaseTypes()

	/**
	 * The current case types the current user may see, sorted by title.
	 *
	 * OpenRegister applies RBAC under the user session. A superseded version is
	 * left out: the list offers one row per case type, not one per version.
	 *
	 * @return array<int, array{id: string, title: string, icon?: string}> The case types; empty when
	 *   OpenRegister is unavailable or the register or schema is unconfigured.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function visibleCaseTypes(): array {
		return array_map(
			static fn (array $row): array => self::publicRow(row: $row),
			$this->currentCaseTypes()
		);
	}//end visibleCaseTypes()

	/**
	 * The current case types with one of the given identifiers, keyed by
	 * identifier, for views an instance names by identifier.
	 *
	 * @param array<int, string> $identifiers The case type identifiers.
	 *
	 * @return array<string, array{id: string, title: string}> The case types found.
	 *
	 * @spec openspec/specs/case-lists/spec.md
	 */
	public function byIdentifier(array $identifiers): array {
		if ($identifiers === []) {
			return [];
		}

		$found = [];
		foreach ($this->currentCaseTypes() as $row) {
			if ($row['identifier'] !== '' && in_array($row['identifier'], $identifiers, true) === true) {
				$found[$row['identifier']] = ['id' => $row['id'], 'title' => $row['title']];
			}
		}

		return $found;
	}//end byIdentifier()

	/**
	 * The current visible case types with their handling teams, sorted by title.
	 *
	 * @return array<int, array{id: string, title: string, identifier: string, icon: string, teams: array<int, string>}> The case types.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
	 * @spec openspec/specs/case-type-navigation/spec.md
	 */
	private function currentCaseTypes(): array {
		$rows = $this->caseTypeRows();
		if ($rows === null) {
			return [];
		}

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

			$caseTypes[$uuid] = $this->menuEntry(row: $row, uuid: $uuid);
		}

		return $this->byTitle(caseTypes: array_values($caseTypes));
	}//end currentCaseTypes()

	/**
	 * The stored case type rows, or null when the register is not configured.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function caseTypeRows(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 500]
		);
	}//end caseTypeRows()

	/**
	 * One case type as the menu lists it.
	 *
	 * The title is declared translatable, so a row carries it as a language
	 * map. A (string) cast of that map is "Array", which is what every case
	 * type in the picker, the chosen list and the menu was called (finding
	 * B2, 10 Oct).
	 *
	 * @param array<string, mixed> $row  The case type row.
	 * @param string               $uuid Its uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function menuEntry(array $row, string $uuid): array {
		$title = $this->text->forReader(value: ($row['title'] ?? ''));
		if ($title === '') {
			$title = $uuid;
		}

		return [
			'id' => $uuid,
			'title' => $title,
			'identifier' => (string)($row['identifier'] ?? ''),
			'icon' => $this->iconOf(row: $row),
			'teams' => (new CaseTypeHandling())->teams(caseType: $row),
		];
	}//end menuEntry()

	/**
	 * The case types in title order, case-insensitively.
	 *
	 * @param array<int, array<string, mixed>> $caseTypes The case types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function byTitle(array $caseTypes): array {
		usort(
			$caseTypes,
			static function (array $left, array $right): int {
				return strcasecmp($left['title'], $right['title']);
			}
		);

		return $caseTypes;
	}//end byTitle()

	/**
	 * The user's chosen case types, in their order, limited to what they may see.
	 *
	 * @param string $userId The user.
	 * @param array<int, array{id: string, title: string}> $visible The result of offeredCaseTypes().
	 *
	 * @return array<int, array{id: string, title: string}> The chosen case types in menu order.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
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
	 * Keep the ids that name a visible case type, first occurrence only, up to the cap.
	 * An id of a superseded version counts as the version in use.
	 *
	 * @param array<int, mixed> $ids The ids in order.
	 * @param array<int, array{id: string, title: string}> $visible The visible case types.
	 *
	 * @return array<int, array{id: string, title: string}> The kept case types in order.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	private function keepVisible(array $ids, array $visible): array {
		$byId = array_column($visible, null, 'id');
		$versions = new SupersededCaseTypes();

		$kept = [];
		foreach ($ids as $id) {
			if (is_string($id) === false) {
				continue;
			}

			// A choice made on a version superseded since names the version in use.
			$id = $versions->currentVersionOf(uuid: $id, supersededBy: $this->supersededBy);
			if (isset($byId[$id]) === false || isset($kept[$id]) === true) {
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
	 * @spec openspec/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
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
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
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
