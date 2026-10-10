<?php

/**
 * Dossiq Menu Case Types Service.
 *
 * The case types each user chose for the "My case types" heading of the
 * sidebar, in their own order (board DqPersoonlijkeInstellingen). The choice
 * is an IConfig user value holding a JSON array of case type uuids; every read
 * intersects it with the case types the user may see right now, so a retired
 * or no longer visible case type drops out of the menu without a write.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IConfig;

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register, schema, ObjectService).
	 * @param IConfig $config Nextcloud config (user values).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IConfig $config,
	) {
	}//end __construct()

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

		$caseTypes = [];
		foreach ($rows as $row) {
			if (empty($row['supersededBy']) === false) {
				continue;
			}

			$uuid = $this->resolveUuid(object: $row);
			if ($uuid === null || isset($caseTypes[$uuid]) === true) {
				continue;
			}

			$caseTypes[$uuid] = ['id' => $uuid, 'title' => (string)($row['title'] ?? $uuid)];
		}

		$list = array_values($caseTypes);
		usort(
			$list,
			static function (array $left, array $right): int {
				return strcasecmp($left['title'], $right['title']);
			}
		);

		return $list;
	}//end visibleCaseTypes()

	/**
	 * The user's chosen case types, in their order, limited to what they may see.
	 *
	 * @param string $userId The user.
	 * @param array<int, array{id: string, title: string}> $visible The result of visibleCaseTypes().
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
	 * @param array<int, array{id: string, title: string}> $visible The result of visibleCaseTypes().
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
