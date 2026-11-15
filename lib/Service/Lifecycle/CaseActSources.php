<?php

/**
 * Where the acts a case offers come from: the menu and the ending, hold and draft acts.
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
 * Where the acts a case offers come from: the menu and the ending, hold and draft acts.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-finishing-or-aborting-a-case-picks-its-result-from-the-case-type-req-cm-80
 */
final class CaseActSources {
	/**
	 * Constructor.
	 *
	 * @param CaseActsMenu   $menu    What the caller may do, and who the case waits on.
	 * @param CaseEndingActs $endings Whether and how the case ended.
	 * @param CaseHoldActs   $holds   Whether the case is held.
	 * @param DraftCaseActs  $drafts  Whether the case is still a draft.
	 */
	public function __construct(
		public readonly CaseActsMenu $menu,
		public readonly CaseEndingActs $endings,
		public readonly CaseHoldActs $holds,
		public readonly DraftCaseActs $drafts,
	) {
	}//end __construct()
}//end class
