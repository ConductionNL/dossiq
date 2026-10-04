<?php

/**
 * Dossiq Portal Case Steps (site-resident-portal-design D3)
 *
 * Reads what {@see CaseSteps} folds: one case, and the status types of its
 * own case type. The folding has no reads in it and this class has no
 * folding in it, so the rule ("consecutive statuses sharing a public label
 * are one step") can be asserted without a store, and the reading can fail
 * without taking the rule with it.
 *
 * A read that fails costs the resident the step list and not the page, the
 * same decision {@see PortalCaseDocuments} makes.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-reads-where-their-case-stands-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public steps of one case, read from the case and its case type.
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-reads-where-their-case-stands-req-srpd-003
 */
class PortalCaseSteps {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the register, the schemas and the object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The steps of one case, or [] when they cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, string>> The steps.
	 *
	 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-the-resident-reads-where-their-case-stands-req-srpd-003
	 */
	public function forCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($caseId === '' || $objectService === null || $register === '') {
			return [];
		}

		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->steps(objectService: $objectService, register: $register, caseId: $caseId)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the public steps of a case could not be read',
				['case' => $caseId, 'error' => $e->getMessage()]
			);
			return [];
		}
	}//end forCase()

	/**
	 * Read the case and its case type's status types, then fold them.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param int|string $register The register.
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, string>> The steps.
	 */
	private function steps(object $objectService, int|string $register, string $caseId): array {
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$statusSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
		if ($caseSchema === '' || $statusSchema === '') {
			return [];
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);
		if ($case === null) {
			return [];
		}

		$caseType = trim((string)($case['caseType'] ?? ''));
		if ($caseType === '') {
			return [];
		}

		$statusTypes = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $statusSchema,
			filters: ['caseType' => $caseType, '_limit' => 100]
		);

		return (new CaseSteps())->forCase(case: $case, statusTypes: $statusTypes);
	}//end steps()
}//end class
