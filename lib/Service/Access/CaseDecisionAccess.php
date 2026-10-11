<?php

/**
 * Dossiq case decision access
 *
 * Answers OpenRegister's question whether somebody other than the decider may
 * change a decision on an object (decision 197): through the case the object
 * belongs to, with the supervising group of that case's case type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;

/**
 * Who may change a decision on a case's object: a supervisor of every guarded case it belongs to.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */
class CaseDecisionAccess {

	/**
	 * The refusal reason handed to OpenRegister.
	 */
	public const REFUSAL = 'case-supervisor-required';

	/**
	 * The field on a case document that holds the document.
	 */
	private const DOCUMENT_FIELD = 'document';

	/**
	 * The field on a case document that holds the case.
	 */
	private const CASE_FIELD = 'case';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The case document schema.
	 * @param CaseRecordLookup   $lookup          The case, its case type and its rows, unscoped.
	 * @param CaseTypeStore      $caseTypes       Reference ids.
	 * @param CaseReviewerAccess $access          The declaration and who supervises.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRecordLookup $lookup,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseReviewerAccess $access,
	) {
	}//end __construct()

	/**
	 * True to allow, false to refuse, null when no guarded case holds the object.
	 *
	 * @param string $objectUuid The object the decision is on (a case or a document).
	 * @param string $userId     The user who wants to change the decision.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
	 */
	public function mayChange(string $objectUuid, string $userId): ?bool {
		$guarded = false;
		foreach ($this->casesOf(objectUuid: $objectUuid) as $case) {
			$declaration = $this->access->declarationFor(caseType: $this->lookup->caseTypeOf(case: $case));
			if ($declaration['records'] === []) {
				continue;
			}

			if ($this->access->isSupervisor(userId: $userId, group: $declaration['supervisorGroup']) === false) {
				return false;
			}

			$guarded = true;
		}

		if ($guarded === false) {
			return null;
		}

		return true;
	}//end mayChange()

	/**
	 * The case rows the object belongs to: itself when it is a case, else the cases that link it as a document.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function casesOf(string $objectUuid): array {
		if ($objectUuid === '') {
			return [];
		}

		// Only a row with a case type counts as a case: a find by uuid may
		// answer the object from another schema, and a case without a case
		// type has nothing to guard anyway.
		$case = $this->lookup->caseRow(caseId: $objectUuid);
		if ($this->caseTypes->referenceId(value: ($case['caseType'] ?? '')) !== '') {
			return [$case];
		}

		$cases = [];
		$links = $this->lookup->rowsOf(
			schema: $this->settingsService->getConfigValue('case_document_schema'),
			field: self::DOCUMENT_FIELD,
			caseId: $objectUuid
		);
		foreach ($links as $link) {
			$caseId = $this->caseTypes->referenceId(value: ($link[self::CASE_FIELD] ?? ''));
			if ($caseId === '' || isset($cases[$caseId]) === true) {
				continue;
			}

			$row = $this->lookup->caseRow(caseId: $caseId);
			if ($row !== []) {
				$cases[$caseId] = $row;
			}
		}

		return array_values($cases);
	}//end casesOf()
}//end class
