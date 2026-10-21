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
				$row,
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
