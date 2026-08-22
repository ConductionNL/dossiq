<?php

/**
 * Dossiq deelzaak case-object reader.
 *
 * Single-object OpenRegister lookups for the parent/child (deelzaak) relation:
 * fetching a case or a caseType by id, normalising whatever ObjectService
 * returns into a plain array, and reading the `parentCase` reference out of a
 * case whichever shape OpenRegister rendered it in. Split out of
 * DeelzaakService so that service keeps only the relation logic — listing,
 * batch counting, constraint validation and unlinking — while the knowledge of
 * which register/schema a case lives in, and how tolerant a read has to be,
 * lives here and nowhere else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Deelzaak
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
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Deelzaak;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Reads single case / caseType objects for the deelzaak relation.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
class CaseObjectReader {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a single case object by UUID and normalise it to an array.
	 *
	 * @param string $caseUuid Case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when missing.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function fetchCaseById(string $caseUuid): ?array {
		if ($caseUuid === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$obj = $objectService->find($caseUuid, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Case lookup failed',
				['uuid' => $caseUuid, 'error' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(obj: $obj);
	}//end fetchCaseById()

	/**
	 * Load a caseType by id or slug.
	 *
	 * @param string $caseTypeId Identifier.
	 *
	 * @return array<string, mixed>|null The caseType, or null when missing.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function loadCaseType(string $caseTypeId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$obj = $objectService->find($caseTypeId, register: $register, schema: $schema);
		} catch (\Throwable) {
			return null;
		}

		return $this->toArray(obj: $obj);
	}//end loadCaseType()

	/**
	 * Read the `parentCase` reference UUID out of a case array.
	 *
	 * Tolerates both the scalar-UUID shape (`parentCase: "<uuid>"`) and an
	 * expanded-object shape (`parentCase: { id|uuid: "<uuid>" }`) that OR
	 * may emit when the relation is hydrated.
	 *
	 * @param array<string, mixed> $case Case object as an array.
	 *
	 * @return string The parent UUID, or '' when absent.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function extractParentReference(array $case): string {
		$parent = ($case['parentCase'] ?? null);
		if (is_string($parent) === true) {
			return $parent;
		}

		if (is_array($parent) === true) {
			$ref = ($parent['id'] ?? $parent['uuid'] ?? '');
			if (is_string($ref) === true) {
				return $ref;
			}

			return '';
		}

		return '';
	}//end extractParentReference()

	/**
	 * Normalise an OpenRegister lookup result to an associative array.
	 *
	 * @param mixed $obj The raw lookup result.
	 *
	 * @return array<string, mixed>|null The object as an array, or null when it cannot be coerced.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	private function toArray(mixed $obj): ?array {
		if ($obj === null) {
			return null;
		}

		if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
			$obj = $obj->jsonSerialize();
		}

		if (is_array($obj) === true) {
			return $obj;
		}

		return null;
	}//end toArray()
}//end class
