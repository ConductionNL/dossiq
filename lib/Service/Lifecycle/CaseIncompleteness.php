<?php

/**
 * A required field left empty knowingly, recorded rather than refused.
 *
 * A phone intake cannot always be complete. Refusing it loses the case, which
 * is worse than holding an incomplete one: the caller hangs up and the
 * gemeente has no record that they rang. So a required field may be left empty
 * knowingly, and what the case must not do is report itself complete.
 *
 * Three things follow, and all three are the requirement rather than a
 * convenience:
 *
 *  1. The case is CREATED. Intake is never refused for incompleteness.
 *  2. The case NAMES the missing fields. A count cannot be acted on; a name
 *     can be rung back for.
 *  3. The acts that need the missing data are REFUSED, naming the field. An
 *     incomplete case that could still send a besluit would be the version of
 *     this feature that causes harm.
 *
 * 🔑 THE REQUIRED SET IS THE SCHEMA'S, NOT A SECOND LIST. Which fields a case
 * type requires is declared once, in the register, and read here. A list kept
 * beside it would agree on the day it was written and diverge afterwards, and
 * the divergence would show up as a case that reads complete while a required
 * field is empty, which is the one thing this class exists to prevent.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Records that a case is incomplete, names the fields, and refuses what needs them.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseIncompleteness {

	/**
	 * Whether the case knows it is incomplete.
	 *
	 * @var string
	 */
	public const FLAG_FIELD = 'isIncomplete';

	/**
	 * The names of the fields that are missing.
	 *
	 * @var string
	 */
	public const FIELDS_FIELD = 'missingFields';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads and writes the case.
	 * @param CaseJournal $journal The case's own record.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseJournal $journal,
	) {
	}//end __construct()

	/**
	 * Record on a case which required fields were knowingly left empty.
	 *
	 * Called after the case exists, never before: refusing the intake is the
	 * behaviour this replaces.
	 *
	 * @param string $caseId The case UUID.
	 * @param list<string> $missing The field names left empty.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function record(string $caseId, array $missing): array {
		$case = $this->load(caseId: $caseId);

		$named = [];
		foreach ($missing as $field) {
			$field = trim((string)$field);
			if ($field !== '' && in_array($field, $named, true) === false) {
				$named[] = $field;
			}
		}

		$case[self::FLAG_FIELD] = ($named !== []);
		$case[self::FIELDS_FIELD] = json_encode($named);
		$case = $this->journal->append(
			case: $case,
			entry: ['type' => 'incompleteness', 'fields' => $named],
		);
		$this->store->saveCase(case: $case);

		return [
			'caseId' => $caseId,
			'incomplete' => ($named !== []),
			'missingFields' => $named,
		];
	}//end record()

	/**
	 * The fields a case is missing.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return list<string> The field names, empty when the case is complete.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function missingOn(array $case): array {
		$raw = ($case[self::FIELDS_FIELD] ?? '');
		$decoded = $raw;
		if (is_array($raw) === false) {
			$decoded = json_decode((string)$raw, true);
		}

		if (is_array($decoded) === false) {
			return [];
		}

		$fields = [];
		foreach ($decoded as $field) {
			$field = trim((string)$field);
			if ($field !== '') {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end missingOn()

	/**
	 * Refuse an act that needs data the case does not have.
	 *
	 * The refusal NAMES the field, because "this case is incomplete" sends a
	 * handler to compare the form against the case type by hand.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param list<string> $needs The fields this act needs.
	 *
	 * @return void
	 *
	 * @throws RefusedException When any of them is missing.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function requireFields(array $case, array $needs): void {
		$missing = array_values(array_intersect($this->missingOn(case: $case), $needs));
		if ($missing === []) {
			return;
		}

		throw new RefusedException(
			rule: 'incomplete-case',
			sentence: sprintf('This case is missing %s.', implode(', ', $missing)),
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end requireFields()

	/**
	 * Load the case, or refuse.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec exclude one read behind record(), which carries the requirement
	 */
	private function load(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end load()
}//end class
