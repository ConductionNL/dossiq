<?php

/**
 * What a new case takes from its case type when nobody filled it in.
 *
 * A case a handler creates in dossiq gets these from the create form: the
 * `caseType` property's `x-openregister-prefill` block fills the first status
 * and the handler, and the form asks for the confidentiality. A case written
 * by the system, such as a request a resident files through the portal, has
 * no form. Before this class it was saved with no handler, no handling group,
 * no confidentiality and no moment it entered its first status, so the case
 * list showed it unassigned and the dwell clock had nothing to count from.
 *
 * Every value comes from the case type, read through the one reader of its
 * handling block ({@see CaseTypeHandling}). A value the case already carries
 * always wins: this fills gaps, it never overrides what the caller wrote.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;

/**
 * Fills a new case's opening fields from its case type.
 *
 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
 */
final class CaseOpeningDefaults {

	/**
	 * The one reader of a case type's handling block.
	 *
	 * @var CaseTypeHandling
	 */
	private readonly CaseTypeHandling $handling;

	/**
	 * Constructor.
	 *
	 * @param CaseTypeHandling|null $handling The handling reader; a fresh one when null.
	 */
	public function __construct(?CaseTypeHandling $handling = null) {
		$this->handling = ($handling ?? new CaseTypeHandling());
	}//end __construct()

	/**
	 * The case, with every opening field it lacks taken from its case type.
	 *
	 * @param array<string, mixed>   $case     The case about to be written.
	 * @param array<string, mixed>   $caseType The case type it is of.
	 * @param DateTimeImmutable|null $now      The moment of the write; now when null.
	 *
	 * @return array<string, mixed> The case, gaps filled.
	 *
	 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
	 */
	public function apply(array $case, array $caseType, ?DateTimeImmutable $now = null): array {
		$fromType = [
			'status' => trim((string)($caseType['initialStatus'] ?? '')),
			'assignee' => $this->handling->defaultHandler(caseType: $caseType),
			'assignedGroup' => $this->handling->defaultGroup(caseType: $caseType),
			'confidentiality' => trim((string)($caseType['confidentiality'] ?? '')),
		];

		foreach ($fromType as $field => $value) {
			if ($value !== '' && $this->isEmpty(value: ($case[$field] ?? null)) === true) {
				$case[$field] = $value;
			}
		}

		// The case enters its first status at the moment it is written, as a
		// move into a status stamps it (StatusDwellService::applyStatusChange).
		if ($this->isEmpty(value: ($case['status'] ?? null)) === false
			&& $this->isEmpty(value: ($case['currentStatusEnteredAt'] ?? null)) === true
		) {
			$case['currentStatusEnteredAt'] = ($now ?? new DateTimeImmutable())->format('c');
		}

		return $case;
	}//end apply()

	/**
	 * Whether a field counts as not filled in.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return bool True for null, '' and whitespace.
	 */
	private function isEmpty(mixed $value): bool {
		return ($value === null || (is_string($value) === true && trim($value) === ''));
	}//end isEmpty()
}//end class
