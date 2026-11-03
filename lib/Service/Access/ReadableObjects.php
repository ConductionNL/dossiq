<?php

/**
 * Dossiq readable objects: whether the caller may read an object, asked of OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Integration\CaseLeafProvider;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;

/**
 * Answers whether the signed-in caller may read an object, by reading it with
 * the caller's own rights. OpenRegister decides; nothing here widens or
 * narrows that answer. A read that fails throws, so a caller can tell an
 * outage from an object it may not read.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class ReadableObjects {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and OpenRegister access.
	 * @param IUserSession    $userSession     The signed-in caller.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether a caller is signed in; without one nothing is readable.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaller(): bool {
		return $this->userSession->getUser() !== null;
	}//end hasCaller()

	/**
	 * Whether the caller may read the object with this id in this schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object's uuid.
	 *
	 * @return bool True when the caller's own read finds it.
	 *
	 * @throws \Throwable When the register cannot be read.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function canRead(string $schema, string $id): bool {
		$id = trim($id);
		if ($id === '') {
			return false;
		}

		return $this->rowsOf(schema: $schema, filters: ['_ids' => [$id], '_limit' => 1]) !== [];
	}//end canRead()

	/**
	 * The master entities the caller may read: those a person or company the caller may read carries.
	 *
	 * @param array<int, string> $refs The master entity references.
	 *
	 * @return array<int, string> The readable references, in the order asked.
	 *
	 * @throws \Throwable When the register cannot be read.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function readableMasterEntities(array $refs): array {
		if ($refs === []) {
			return [];
		}

		$carried = [];
		foreach (CaseLeafProvider::PARTY_SCHEMAS as $schema) {
			foreach ($this->rowsOf(schema: $schema, filters: ['masterEntityRef' => array_values($refs), '_limit' => count($refs) * 10]) as $party) {
				$carried[(string)($party['masterEntityRef'] ?? '')] = true;
			}
		}

		return array_values(array_filter($refs, static fn (string $ref): bool => isset($carried[$ref]) === true));
	}//end readableMasterEntities()

	/**
	 * Rows of one schema under the caller's own rights; none without a caller or a configured register.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When the register cannot be read.
	 */
	private function rowsOf(string $schema, array $filters): array {
		$register = (string)$this->settingsService->getConfigValue('register');
		$objectService = $this->settingsService->getObjectService();
		if ($this->hasCaller() === false || $register === '' || $objectService === null) {
			return [];
		}

		return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
	}//end rowsOf()
}//end class
