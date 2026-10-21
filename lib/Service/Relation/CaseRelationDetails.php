<?php

/**
 * What a related case is, for the row that lists it.
 *
 * The relation rows carry the other case's id and title. Board
 * DqZaakGerelateerd draws each related case with its number, its case type,
 * its status and its handler, so this adds those, read from the case and its
 * case type. A case the reader may not open keeps the row as it was: its
 * title, and nothing more. A row without a title is left out.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use OCP\IUserManager;

/**
 * Adds number, case type, status and handler to related-case rows.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
class CaseRelationDetails {
	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store       Reads the cases and case types.
	 * @param IUserManager      $userManager Names the handler.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Describe each related case the reader may open.
	 *
	 * @param array<int, array<string, mixed>> $rows    The relation rows, each with `caseId`.
	 * @param callable(string): bool           $canRead Whether the reader may open a case.
	 *
	 * @return array<int, array<string, mixed>> The rows that have a title, with
	 *         `identifier`, `caseTypeTitle`, `statusLabel`, `statusRole`,
	 *         `endDate`, `assignee` and `assigneeName` on the readable ones.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function describe(array $rows, callable $canRead): array {
		$caseTypeTitles = [];
		foreach ($rows as $index => $row) {
			$rows[$index]['group'] = $this->groupOf(row: $row);
			$caseId = (string)($row['caseId'] ?? '');
			if ($caseId === '' || $canRead($caseId) !== true) {
				continue;
			}

			$case = $this->store->fetchCase(caseUuid: $caseId);
			if ($case === null) {
				continue;
			}

			$caseTypeId = (string)($case['caseType'] ?? '');
			if ($caseTypeId !== '' && array_key_exists($caseTypeId, $caseTypeTitles) === false) {
				$caseType = $this->store->fetchCaseType(caseTypeUuid: $caseTypeId);
				$caseTypeTitles[$caseTypeId] = trim((string)($caseType['title'] ?? ''));
			}

			$assignee = (string)($case['assignee'] ?? '');
			$rows[$index] = array_merge(
				$rows[$index],
				[
					'identifier'    => (string)($case['identifier'] ?? ''),
					'caseTypeTitle' => $caseTypeTitles[$caseTypeId] ?? '',
					'statusLabel'   => (string)($case['statusPublicLabel'] ?? ''),
					'statusRole'    => (string)($case['statusRole'] ?? ''),
					'endDate'       => (string)($case['endDate'] ?? ''),
					'assignee'      => $assignee,
					'assigneeName'  => $this->nameOf(uid: $assignee),
				]
			);
		}//end foreach

		// A related case without a readable title cannot be named in a list,
		// so it is left out, as the related card always left it out.
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => trim((string)($row['title'] ?? '')) !== ''
			)
		);
	}//end describe()

	/**
	 * The planned follow-ups of a case as rows of the same list, in the
	 * `planned` group: a planned flow or a series, with its next date. They
	 * open no case, so they carry no `caseId`.
	 *
	 * @param array<int, array<string, mixed>> $planned The planned rows, as the planned endpoint lists them.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function plannedRows(array $planned): array {
		$rows = [];
		foreach ($planned as $entry) {
			$recurrence = (string)($entry['recurrence'] ?? 'none');
			$kind       = 'series';
			if ($recurrence === 'none') {
				$kind = 'flow';
			}

			$occurrences = 0;
			if (is_array($entry['occurrences'] ?? null) === true) {
				$occurrences = count($entry['occurrences']);
			}

			$rows[] = [
				'id'          => (string)($entry['id'] ?? ''),
				'title'       => (string)($entry['title'] ?? ''),
				'group'       => 'planned',
				'statusRole'  => 'planned',
				'plannedKind' => $kind,
				'recurrence'  => $recurrence,
				'date'        => (string)($entry['date'] ?? ''),
				'occurrences' => $occurrences,
			];
		}

		return array_values(array_filter($rows, static fn (array $row): bool => $row['title'] !== ''));
	}//end plannedRows()

	/**
	 * The group a relation row lists under: the label the reader's side of
	 * the relation carries, else the relation type.
	 *
	 * @param array<string, mixed> $row The relation row.
	 *
	 * @return string The group.
	 */
	private function groupOf(array $row): string {
		$label = trim((string)($row['displayLabel'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		return (string)($row['aardRelatie'] ?? '');
	}//end groupOf()

	/**
	 * A handler's display name, or the user id when the account is gone.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The name, or '' without a handler.
	 */
	private function nameOf(string $uid): string {
		if ($uid === '') {
			return '';
		}

		return $this->userManager->getDisplayName($uid) ?? $uid;
	}//end nameOf()
}//end class
