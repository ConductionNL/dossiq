<?php

/**
 * Dossiq live case reader.
 *
 * The live case a retention clock reads, from the configured case store.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one live case from the configured case store; a store that cannot be read is logged, not answered.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class LiveCaseReader {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus app config.
	 * @param LoggerInterface $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The live case, or null when there is no id, no configured store, or the store could not be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function read(string $caseId): ?array {
		$location = $this->caseStoreLocation();
		if ($location === null || $caseId === '') {
			return null;
		}

		return $this->readLiveCase(location: $location, caseId: $caseId);
	}//end read()

	/**
	 * Where the live cases are stored, or null when OpenRegister or its configuration is missing.
	 *
	 * @return CaseStoreLocation|null The location.
	 */
	private function caseStoreLocation(): ?CaseStoreLocation {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return new CaseStoreLocation(objectService: $objectService, register: $register, schema: $schema);
	}//end caseStoreLocation()

	/**
	 * Read one live case; a store that cannot be read is logged and answers null.
	 *
	 * @param CaseStoreLocation $location Where the case is stored.
	 * @param string            $caseId   The case UUID.
	 *
	 * @return array<string, mixed>|null The case payload, or null.
	 */
	private function readLiveCase(CaseStoreLocation $location, string $caseId): ?array {
		// The catch LOGS and does not answer: a store that could not be read is
		// a different fact from a case that is not there, and a bare `return
		// null` in the catch makes the two indistinguishable downstream.
		$case = null;
		try {
			$case = $this->findObjectAsArray(
				objectService: $location->objectService,
				register: $location->register,
				schema: $location->schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the case behind the retention clocks could not be read',
				['caseId' => $caseId, 'error' => $e->getMessage()]
			);
		}

		return $case;
	}//end readLiveCase()
}//end class
