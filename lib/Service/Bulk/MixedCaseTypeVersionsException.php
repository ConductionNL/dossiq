<?php

/**
 * Dossiq: the selection spans more than one version of a case type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bulk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bulk;

use RuntimeException;

/**
 * Raised at selection time, before any rehearsal runs.
 *
 * It carries the versions rather than a sentence about them, because the
 * refusal a handler reads has to name both: "these are on version 2 and these
 * on version 3" is actionable, "mixed versions" is not (D-4).
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class MixedCaseTypeVersionsException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string             $caseTypeTitle The case type the selection is split across.
	 * @param array<int, int>    $versions      The versions present, ascending.
	 * @param array<string, int> $counts        How many cases sit on each version.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $caseTypeTitle,
		private readonly array $versions,
		private readonly array $counts,
	) {
		parent::__construct('mixed_case_type_versions');
	}//end __construct()

	/**
	 * The case type the selection is split across.
	 *
	 * @return string The title.
	 */
	public function getCaseTypeTitle(): string {
		return $this->caseTypeTitle;
	}//end getCaseTypeTitle()

	/**
	 * The versions present in the selection.
	 *
	 * @return array<int, int> The versions, ascending.
	 */
	public function getVersions(): array {
		return $this->versions;
	}//end getVersions()

	/**
	 * How many cases sit on each version.
	 *
	 * @return array<string, int> Version to count.
	 */
	public function getCounts(): array {
		return $this->counts;
	}//end getCounts()
}//end class
