<?php

/**
 * Dossiq case record readers
 *
 * Which people read one record a case type keeps private to the case
 * (case-reviewer-access, REQ-CRA-008). A case type says per record, under
 * `reviewerAccess.records[].readers`, how far it is shared on the case:
 *
 *  - `all` (the default): the case assignee and every reviewer named on the
 *    case, unless the case type hides reviewers from each other;
 *  - `assignee`: the case assignee only;
 *  - `own`: the case assignee and the reviewers the record itself names,
 *    either directly (`userField`) or through a row that names reviewers
 *    (`reviewerRowField`, the id of a row of one of the `reviewers` sources).
 *
 * The supervising group owns every record in all three, so it is not a reader
 * here. A value this class does not know is read as `assignee`: the narrow
 * side is the one that can be repaired without a leak.
 *
 * Pure computation: no OpenRegister call, no procedure named.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-reads-it-req-cra-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * The readers of one private record of a case.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-reads-it-req-cra-008
 */
class CaseRecordReaders {

	/**
	 * The assignee and every reviewer on the case.
	 */
	public const ALL = 'all';

	/**
	 * The case assignee only.
	 */
	public const ASSIGNEE = 'assignee';

	/**
	 * The assignee and the reviewers the record names.
	 */
	public const OWN = 'own';

	/**
	 * One declared record's reader rule, normalised.
	 *
	 * @param array<string, mixed> $declared The `reviewerAccess.records` entry.
	 *
	 * @return array{readers: string, userField: string, reviewerRowField: string}
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-reads-it-req-cra-008
	 */
	public function ruleOf(array $declared): array {
		$readers = trim((string)($declared['readers'] ?? ''));
		if ($readers === '') {
			$readers = self::ALL;
		}

		if (in_array($readers, [self::ALL, self::ASSIGNEE, self::OWN], true) === false) {
			$readers = self::ASSIGNEE;
		}

		return [
			'readers' => $readers,
			'userField' => trim((string)($declared['userField'] ?? '')),
			'reviewerRowField' => trim((string)($declared['reviewerRowField'] ?? '')),
		];
	}//end ruleOf()

	/**
	 * The user ids that must read one record.
	 *
	 * @param array<string, string>       $rule       The record's rule, as ruleOf() answers it.
	 * @param array<string, mixed>        $record     The record row.
	 * @param string                      $assignee   The case assignee, '' when none.
	 * @param array<string, list<string>> $reviewers  The reviewers of the case, by the id of the row that names them.
	 * @param bool                        $hideOthers Whether reviewers are hidden from each other.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-reads-it-req-cra-008
	 */
	public function readersFor(array $rule, array $record, string $assignee, array $reviewers, bool $hideOthers): array {
		$readers = [$assignee];
		if ($rule['readers'] === self::ALL && $hideOthers === false) {
			$readers = array_merge($readers, ...array_values($reviewers));
		}

		if ($rule['readers'] === self::OWN) {
			$readers = array_merge($readers, $this->namedOn(rule: $rule, record: $record, reviewers: $reviewers));
		}

		$readers = array_map(static fn (mixed $user): string => trim((string)$user), $readers);

		return array_values(array_unique(array_filter($readers, static fn (string $user): bool => $user !== '')));
	}//end readersFor()

	/**
	 * The reviewers one record names, directly or through a reviewer row.
	 *
	 * @param array<string, string>       $rule      The record's rule.
	 * @param array<string, mixed>        $record    The record row.
	 * @param array<string, list<string>> $reviewers The reviewers of the case, by row id.
	 *
	 * @return list<mixed>
	 */
	private function namedOn(array $rule, array $record, array $reviewers): array {
		$named = [];
		if ($rule['userField'] !== '') {
			$named = array_values(array_filter((array)($record[$rule['userField']] ?? []), 'is_scalar'));
		}

		if ($rule['reviewerRowField'] === '') {
			return $named;
		}

		foreach ((array)($record[$rule['reviewerRowField']] ?? []) as $key => $reference) {
			// A reference is a uuid, a list of uuids, or one serialised row.
			if (is_array($reference) === true) {
				$reference = ($reference['id'] ?? ($reference['uuid'] ?? ''));
			} else if (is_string($key) === true && in_array($key, ['id', 'uuid'], true) === false) {
				continue;
			}

			$named = array_merge($named, ($reviewers[trim((string)$reference)] ?? []));
		}

		return $named;
	}//end namedOn()
}//end class
