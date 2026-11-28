<?php

/**
 * Dossiq live case retention clocks.
 *
 * The lawful-purpose clock and the archive clock of a case that still exists:
 * the live case is read (not the trash), and its clocks are worked out.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseRecycleServiceInterface;
use OCA\Dossiq\Kernel\Contracts\RetentionClocksInterface;

/**
 * Reads a live case and answers its two retention clocks.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class LiveCaseRetentionClocks {

	/**
	 * Constructor.
	 *
	 * @param CaseRecycleServiceInterface $recycle Reads the live case.
	 * @param RetentionClocksInterface    $clocks  Works out the two clocks.
	 */
	public function __construct(
		private readonly CaseRecycleServiceInterface $recycle,
		private readonly RetentionClocksInterface $clocks,
	) {
	}//end __construct()

	/**
	 * The two clocks of a live case, or null when there is no live case by that id.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{lawfulPurpose: array<string, mixed>, archive: array<string, mixed>, disagree: bool}|null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function forCase(string $caseId): ?array {
		$case = $this->recycle->liveCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		return $this->clocks->clocksFor(case: $case);
	}//end forCase()
}//end class
