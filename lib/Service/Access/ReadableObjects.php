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

use OCA\Dossiq\Kernel\Contracts\CaseLeafProviderInterface;
use OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;

/**
 * Answers whether the signed-in caller may read an object, by reading it with
 * the caller's own rights. OpenRegister decides; nothing here widens or
 * narrows that answer. A read that fails throws, so a caller can tell an
 * outage from an object it may not read.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class ReadableObjects implements ReadableObjectsInterface {

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
	public function findsAsCaller(string $schema, string $id): bool {
		$id = trim($id);
		if ($id === '') {
			return false;
		}

		return $this->rowsOf(schema: $schema, filters: ['_ids' => [$id], '_limit' => 1]) !== [];
	}//end findsAsCaller()

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
		foreach (CaseLeafProviderInterface::PARTY_SCHEMAS as $schema) {
			$open = array_values(array_unique(array_filter($refs, static fn (string $ref): bool => isset($carried[$ref]) === false)));
			$carried += $this->carriedIn(schema: $schema, refs: $open);
		}

		return array_values(array_filter($refs, static fn (string $ref): bool => isset($carried[$ref]) === true));
	}//end readableMasterEntities()

	/**
	 * The references a readable party of one schema carries.
	 *
	 * Reads page by page, each time only for the references not found yet, so
	 * many parties sharing one reference cannot push another reference out of
	 * the page. A register without the schema carries none of them.
	 *
	 * @param string             $schema The party schema.
	 * @param array<int, string> $refs   The references not found yet.
	 *
	 * @return array<string, true> The carried references.
	 *
	 * @throws \Throwable When the register cannot be read.
	 */
	private function carriedIn(string $schema, array $refs): array {
		$carried = [];
		while ($refs !== []) {
			$limit = (count($refs) * 10);
			try {
				$rows = $this->rowsOf(schema: $schema, filters: ['masterEntityRef' => $refs, '_limit' => $limit]);
			} catch (DoesNotExistException) {
				return $carried;
			}

			$found = array_intersect($refs, array_map(static fn (array $party): string => (string)($party['masterEntityRef'] ?? ''), $rows));
			$carried += array_fill_keys($found, true);
			if (count($rows) < $limit || $found === []) {
				return $carried;
			}

			$refs = array_values(array_diff($refs, $found));
		}

		return $carried;
	}//end carriedIn()

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
