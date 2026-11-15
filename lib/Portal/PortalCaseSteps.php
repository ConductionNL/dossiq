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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public steps of one case, read from the case and its case type.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */
class PortalCaseSteps {
	use SearchesObjects;

	/**
	 * Reads the case unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface           $settingsService Resolves the register, the schemas and the object service.
	 * @param LoggerInterface           $logger          The logger.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * The steps of one case, or [] when they cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, string>> The steps.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
	 */
	public function forCase(string $caseId): array {
		$read = (new PortalCaseReads(settingsService: $this->settingsService))->open(caseId: $caseId);
		if ($read === null) {
			return [];
		}

		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $read->objectService,
				operation: fn (): array => $this->steps(read: $read)
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
	 * @param PortalCaseRead $read The case being read.
	 *
	 * @return array<int, array<string, string>> The steps.
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	private function steps(PortalCaseRead $read): array {
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$statusSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
		if ($caseSchema === '' || $statusSchema === '') {
			return [];
		}

		$case = $this->readCase(read: $read, caseSchema: $caseSchema);
		$caseType = trim((string)($case['caseType'] ?? ''));
		if ($case === null || $caseType === '') {
			return [];
		}

		$statusTypes = $this->statusTypesOf(read: $read, statusSchema: $statusSchema, caseType: $caseType);

		return (new CaseSteps())->forCase(case: $case, statusTypes: $statusTypes);
	}//end steps()

	/**
	 * Read the case, unscoped.
	 *
	 * @param PortalCaseRead $read       The case being read.
	 * @param string         $caseSchema The case schema.
	 *
	 * @return array<string, mixed>|null The case, or null when it is not found.
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	private function readCase(PortalCaseRead $read, string $caseSchema): ?array {
		// Unscoped, as the history is: runAsSystem lifts RBAC only for a
		// request without a Nextcloud user, and a person reading a case
		// under a mandate is signed in as themselves, not as the case's
		// party. Portaliq proved the case is theirs to read before asking.
		return $this->reader->find(
			objectService: $read->objectService,
			register: $read->register,
			schema: $caseSchema,
			id: $read->caseId
		);
	}//end readCase()

	/**
	 * The status types of the case's case type, unscoped.
	 *
	 * @param PortalCaseRead $read         The case being read.
	 * @param string         $statusSchema The status type schema.
	 * @param string         $caseType     The case type.
	 *
	 * @return array<int, array<string, mixed>> The status types.
	 *
	 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
	 */
	private function statusTypesOf(PortalCaseRead $read, string $statusSchema, string $caseType): array {
		return $this->searchObjectsAsArraysUnscoped(
			objectService: $read->objectService,
			register: $read->register,
			schema: $statusSchema,
			filters: ['caseType' => $caseType, '_limit' => 100]
		);
	}//end statusTypesOf()
}//end class
