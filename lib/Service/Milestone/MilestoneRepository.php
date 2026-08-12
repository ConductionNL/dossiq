<?php

/**
 * Procest milestone repository.
 *
 * The single OpenRegister read path for milestone definitions (per caseType)
 * and milestone records (per case). Split out of MilestoneService so both that
 * service and StalledCaseDetector can read the same data without either
 * depending on the other — a repository in the middle is what keeps those two
 * collaborators free of a construction cycle.
 *
 * An unconfigured definition/record schema is not an error on the read path:
 * it yields an empty list, exactly as the pre-split service did. A missing
 * OpenRegister *is* an error on the definition path, because a caller that
 * silently sees "no milestones" would report every case as having no progress.
 *
 * @category Service
 * @package  OCA\Procest\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Milestone;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * OpenRegister reads for milestone definitions and records.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
class MilestoneRepository {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Get the milestone definitions declared for a case type.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<int, array<string, mixed>> Milestone definitions.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function findDefinitions(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('milestone_definition_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseType' => $caseTypeId, '_limit' => 100],
		);
	}//end findDefinitions()

	/**
	 * Get the milestone records recorded against a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> Milestone records.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function findRecords(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('milestone_record_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['case' => $caseId, '_limit' => 100],
		);
	}//end findRecords()
}//end class
