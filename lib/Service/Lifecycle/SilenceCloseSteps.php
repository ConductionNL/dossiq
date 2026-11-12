<?php

/**
 * What closing a silent case goes through: the case type's lifecycle rules, the ending acts and the case journal.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

/**
 * What closing a silent case goes through: the case type's lifecycle rules, the ending acts and the case journal.
 *
 * @spec openspec/specs/case-status-machinery/spec.md
 */
final class SilenceCloseSteps {
	/**
	 * Constructor.
	 *
	 * @param LifecycleCaseTypeRules $rules   Reads the declared period and warning.
	 * @param CaseEndingActs         $endings Performs the close as a recorded ending act.
	 * @param CaseJournal            $journal The case's own record.
	 */
	public function __construct(
		public readonly LifecycleCaseTypeRules $rules,
		public readonly CaseEndingActs $endings,
		public readonly CaseJournal $journal,
	) {
	}//end __construct()
}//end class
