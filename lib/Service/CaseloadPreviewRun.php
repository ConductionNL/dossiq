<?php

/**
 * Dossiq CaseloadPreviewRun.
 *
 * One preview of a handler's caseload while it is read: whose caseload, the
 * case type it is narrowed to, and, as it goes, the statuses that close a
 * case and the open cases found.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one preview, built and read by CaseReassignmentService only.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class CaseloadPreviewRun {

	/**
	 * Status ids marking a case as closed or archived, set once they are read.
	 *
	 * @var array<int, string>
	 */
	public array $finalIds = [];

	/**
	 * The open cases, set once they are read.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Constructor.
	 *
	 * @param string $fromUser The departing handler user id.
	 * @param string $caseType The caseType uuid to narrow by ('' = all).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $fromUser,
		public readonly string $caseType,
	) {
	}//end __construct()
}//end class
