<?php

/**
 * Dossiq case split store.
 *
 * Where a split reads and writes: the register and the schema, the uuid a row
 * carries under either of its two keys, and the case itself with the refusal
 * an unreadable one earns.
 *
 * Split out of {@see CaseSplitExecutor}, which was over its complexity
 * ceiling. What is left there is what a split DOES; what moved here is where
 * it looks.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;
use Throwable;

/**
 * Where a case split reads and writes.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitStore {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The stored case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	public function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: CaseSplitExecutor::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: CaseSplitExecutor::CASE_UNREADABLE,
				sentence: 'We could not read that case, so it was not split.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	public function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	public function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	public function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
