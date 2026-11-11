<?php

/**
 * What a case type needs before it is published or deleted
 *
 * Publishing needs a status type, a final one among them, and a valid-from date.
 * Deleting is blocked while active cases use the type and asks for confirmation
 * when only closed cases do. A delete check that cannot read the cases blocks
 * too: "could not look" is not "no cases".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Publish and delete checks of a case type.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcPublishAndDeleteChecks {
	use SearchesObjects;

	/**
	 * The name the delete check goes by in the log.
	 */
	private const DELETE_CHECK = 'ztc case type delete check (REQ-CT-01d)';

	/**
	 * The delete check's answer when the case type may be deleted outright.
	 *
	 * @var array<string, mixed>
	 */
	private const DELETABLE = ['blocked' => false, 'requiresConfirmation' => false, 'message' => ''];

	/**
	 * The delete check's answer when it could not be completed.
	 *
	 * @var array<string, mixed>
	 */
	private const DELETE_UNVERIFIED = [
		'blocked' => true,
		'requiresConfirmation' => false,
		'unverified' => true,
		'message' => 'Cannot delete case type: the check for cases that use this type could not be completed. '
			. 'Nothing was deleted. Try again later.',
	];

	/**
	 * Constructor.
	 *
	 * @param ZgwRuleLookups $lookups Store lookups in the request context.
	 * @param ZgwRuleResults $results Logs a check that could not run.
	 * @param SettingsService $settingsService The settings service.
	 */
	public function __construct(
		private readonly ZgwRuleLookups $lookups,
		private readonly ZgwRuleResults $results,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Validate a caseType is publishable (isDraft true → false).
	 *
	 * REQ-CT-02b. Loads the case type's statusType objects and verifies
	 * preconditions: at least one statusType exists, at least one is final,
	 * and the case type itself has a validFrom date.
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, string> Error strings (empty = valid).
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function validatePublish(string $register, string $caseTypeId): array {
		$refusal = $this->publishCannotRun(caseTypeId: $caseTypeId);
		if ($refusal !== null) {
			return [$refusal];
		}

		$statusSchema = (string)$this->settingsService->getConfigValue(key: 'status_type_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue(key: 'case_type_schema');

		try {
			$statusTypes = $this->caseTypeRows(register: $register, query: new SchemaFilter(schema: $statusSchema, filters: ['caseType' => $caseTypeId]));
		} catch (\Throwable $e) {
			return ['Could not load status types for case type'];
		}

		$caseType = $this->loadCaseTypeRow(
			objectService: $this->lookups->objectService(),
			register: $register,
			schema: $caseSchema,
			caseTypeId: $caseTypeId,
		);

		return array_merge(self::statusTypeErrors(statusTypes: $statusTypes), self::validFromErrors(caseType: $caseType));
	}//end validatePublish()

	/**
	 * Why the publish check cannot run, or null when it can.
	 *
	 * @param string $caseTypeId Case type id.
	 *
	 * @return string|null The reason.
	 */
	private function publishCannotRun(string $caseTypeId): ?string {
		if ($this->lookups->objectService() === null) {
			return 'Cannot validate publish: OpenRegister object service unavailable';
		}

		if ($caseTypeId === '') {
			return 'Cannot validate publish: case type id is empty';
		}

		return null;
	}//end publishCannotRun()

	/**
	 * The rows of one schema that match the filters, read through the request's object service.
	 *
	 * @param string       $register Register slug.
	 * @param SchemaFilter $query    The schema and filters.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @throws \Throwable When the rows cannot be read.
	 */
	private function caseTypeRows(string $register, SchemaFilter $query): array {
		return $this->searchObjectsAsArrays(
			objectService: $this->lookups->objectService(),
			register: $register,
			schema: $query->schema,
			filters: $query->filters,
		);
	}//end caseTypeRows()

	/**
	 * What the status types lack for a publish: any at all, and a final one.
	 *
	 * @param array<int, mixed> $statusTypes The statusType rows for a case type.
	 *
	 * @return array<int, string> Error strings.
	 */
	private static function statusTypeErrors(array $statusTypes): array {
		if (count($statusTypes) === 0) {
			return ['At least one status type must be defined before publishing'];
		}

		if (self::hasFinalStatusType(statusTypes: $statusTypes) === false) {
			return ['At least one status type must be marked as final'];
		}

		return [];
	}//end statusTypeErrors()

	/**
	 * What the case type lacks for a publish: its valid-from date.
	 *
	 * @param array<string, mixed> $caseType The case type row, or [] when it could not be read.
	 *
	 * @return array<int, string> Error strings.
	 */
	private static function validFromErrors(array $caseType): array {
		if ((string)($caseType['validFrom'] ?? '') === '') {
			return ["'Valid from' date must be set before publishing"];
		}

		return [];
	}//end validFromErrors()

	/**
	 * Test whether any of the supplied statusType rows is marked final.
	 *
	 * @param array<int, mixed> $statusTypes The statusType rows for a case type.
	 *
	 * @return bool True when at least one row carries isFinal.
	 */
	private static function hasFinalStatusType(array $statusTypes): bool {
		foreach ($statusTypes as $row) {
			if (is_array($row) === true && (bool)($row['isFinal'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end hasFinalStatusType()

	/**
	 * Load the caseType row itself, returning an empty array when it cannot be read.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register Register slug.
	 * @param string $schema Case type schema slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<string, mixed> The case type row, or an empty array.
	 */
	private function loadCaseTypeRow(object $objectService, string $register, string $schema, string $caseTypeId): array {
		// A top-level `['id' => $caseTypeId]` filter does not resolve in
		// OpenRegister (ids are metadata, not schema properties) and silently
		// matches nothing. The get-by-uuid path resolves the id directly.
		try {
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId
			);
		} catch (\Throwable $e) {
			return [];
		}

		return ($caseType ?? []);
	}//end loadCaseTypeRow()

	/**
	 * Validate a caseType can be safely deleted (no active cases).
	 *
	 * REQ-CT-01d. Returns a triple-shape result:
	 *
	 *   ['blocked' => bool, 'requiresConfirmation' => bool, 'message' => string]
	 *
	 * - blocked=true   → 409 Conflict; active cases exist.
	 * - blocked=true with unverified=true → the cases could not be read, so nobody
	 *   knows whether the type is in use. The caller refuses with the
	 *   "could not be checked" status, not with a conflict.
	 * - requiresConfirmation=true → 200 OK with confirmation prompt (closed-only cases).
	 * - otherwise → the check ran and found no cases: safe to delete.
	 *
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function validateDeletion(string $register, string $caseTypeId): array {
		if ($caseTypeId === '') {
			return self::DELETABLE;
		}

		try {
			$cases = $this->casesOfType(register: $register, caseTypeId: $caseTypeId);
			if (count($cases) === 0) {
				return self::DELETABLE;
			}

			$finalSlugs = $this->loadFinalStatusSlugs(objectService: $this->lookups->objectService(), register: $register, caseTypeId: $caseTypeId);
		} catch (\Throwable $e) {
			// The cases or their final statuses could not be read. Refuse: the type may be in use.
			$this->results->logNotVerified(rule: self::DELETE_CHECK, object: 'case type ' . $caseTypeId, cause: $e->getMessage());

			return self::DELETE_UNVERIFIED;
		}//end try

		return self::deletionVerdict(tally: $this->tallyCaseClosure(cases: $cases, finalSlugs: $finalSlugs));
	}//end validateDeletion()

	/**
	 * The cases that use a case type.
	 *
	 * @param string $register   Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, mixed> The cases.
	 *
	 * @throws \Throwable When there is no object service or the cases cannot be read.
	 */
	private function casesOfType(string $register, string $caseTypeId): array {
		if ($this->lookups->objectService() === null) {
			throw new RuntimeException('No object service in the request context');
		}

		return $this->caseTypeRows(
			register: $register,
			query: new SchemaFilter(
				schema: (string)$this->settingsService->getConfigValue(key: 'case_schema'),
				filters: ['caseType' => $caseTypeId]
			)
		);
	}//end casesOfType()

	/**
	 * The delete verdict from the case tally: active cases block, closed ones ask for confirmation.
	 *
	 * @param array{active: int, closed: int} $tally The tallies.
	 *
	 * @return array<string, mixed> The verdict.
	 */
	private static function deletionVerdict(array $tally): array {
		$activeCount = $tally['active'];
		$closedCount = $tally['closed'];

		if ($activeCount > 0) {
			return [
				'blocked' => true,
				'requiresConfirmation' => false,
				'message' => "Cannot delete case type: $activeCount active case(s) still use this type. "
					. 'Close or reassign all cases first.',
			];
		}

		return [
			'blocked' => false,
			'requiresConfirmation' => true,
			'message' => "Deleting will affect $closedCount closed case(s). Confirm to proceed.",
		];
	}//end deletionVerdict()

	/**
	 * Load the ids of the final statusTypes of a case type. An unreadable list throws.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register Register slug.
	 * @param string $caseTypeId Case type id.
	 *
	 * @return array<int, string> The final statusType ids.
	 *
	 * @throws \Throwable When the status types cannot be read.
	 */
	private function loadFinalStatusSlugs(object $objectService, string $register, string $caseTypeId): array {
		$statusSchema = (string)$this->settingsService->getConfigValue(key: 'status_type_schema');
		$finalStatusTypes = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $statusSchema,
			filters: ['caseType' => $caseTypeId, 'isFinal' => true],
		);

		$finalSlugs = [];
		foreach ($finalStatusTypes as $row) {
			if (is_array($row) === true && isset($row['id']) === true) {
				$finalSlugs[] = (string)$row['id'];
			}
		}

		return $finalSlugs;
	}//end loadFinalStatusSlugs()

	/**
	 * Split a case type's cases into closed (their status is one of the final statusTypes) and
	 * active (everything else, including cases with no status at all).
	 *
	 * @param array<int, mixed> $cases The cases that use the case type.
	 * @param array<int, string> $finalSlugs The final statusType ids.
	 *
	 * @return array{active: int, closed: int} The tallies.
	 */
	private function tallyCaseClosure(array $cases, array $finalSlugs): array {
		$activeCount = 0;
		$closedCount = 0;
		foreach ($cases as $case) {
			$caseStatus = (string)($case['status'] ?? '');
			if ($caseStatus !== '' && in_array($caseStatus, $finalSlugs, true) === true) {
				$closedCount++;
				continue;
			}

			$activeCount++;
		}

		return ['active' => $activeCount, 'closed' => $closedCount];
	}//end tallyCaseClosure()
}//end class
