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
		$run = new RelationDetailsRun(canRead: $canRead);
		foreach ($rows as $index => $row) {
			$rows[$index] = $this->describeRow(run: $run, row: $row);
		}

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
	 * One relation row with its group, and with the related case's details when the reader may open it.
	 *
	 * @param RelationDetailsRun   $run The description pass.
	 * @param array<string, mixed> $row The relation row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function describeRow(RelationDetailsRun $run, array $row): array {
		$row['group'] = $this->groupOf(row: $row);
		$caseId = (string)($row['caseId'] ?? '');
		if ($caseId === '' || ($run->canRead)($caseId) !== true) {
			return $row;
		}

		$related = $this->store->fetchCase(caseUuid: $caseId);
		if ($related === null) {
			return $row;
		}

		return array_merge($row, $this->detailsOf(run: $run, related: $related));
	}//end describeRow()

	/**
	 * The details a readable related case shows in the list.
	 *
	 * @param RelationDetailsRun   $run     The description pass.
	 * @param array<string, mixed> $related The related case.
	 *
	 * @return array<string, string> The details.
	 */
	private function detailsOf(RelationDetailsRun $run, array $related): array {
		$caseTypeTitle = $this->caseTypeTitle(run: $run, caseTypeId: (string)($related['caseType'] ?? ''));
		$assignee = (string)($related['assignee'] ?? '');

		return [
			'identifier'    => (string)($related['identifier'] ?? ''),
			'caseTypeTitle' => $caseTypeTitle,
			'statusLabel'   => (string)($related['statusPublicLabel'] ?? ''),
			'statusRole'    => (string)($related['statusRole'] ?? ''),
			'endDate'       => (string)($related['endDate'] ?? ''),
			'assignee'      => $assignee,
			'assigneeName'  => $this->nameOf(uid: $assignee),
		];
	}//end detailsOf()

	/**
	 * A case type's title, read once per pass.
	 *
	 * @param RelationDetailsRun $run        The description pass.
	 * @param string             $caseTypeId The case type id; an empty id has no title.
	 *
	 * @return string The title.
	 */
	private function caseTypeTitle(RelationDetailsRun $run, string $caseTypeId): string {
		if ($caseTypeId !== '' && array_key_exists($caseTypeId, $run->caseTypeTitles) === false) {
			$caseType = $this->store->fetchCaseType(caseTypeUuid: $caseTypeId);
			$run->caseTypeTitles[$caseTypeId] = trim((string)($caseType['title'] ?? ''));
		}

		return $run->caseTypeTitles[$caseTypeId] ?? '';
	}//end caseTypeTitle()

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
