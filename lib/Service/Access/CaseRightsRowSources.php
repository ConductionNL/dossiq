<?php

/**
 * Where the case matrix's provider rows come from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * The case type rights translator, the compiled rows it last produced, and
 * the assigned group's read rows.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
final class CaseRightsRowSources {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeRightsMatrixRows $caseTypes     Turns case type rights rows into matrix rows.
	 * @param AssignedGroupReadRows    $assignedGroup The assigned group's read rows.
	 * @param CaseTypeRightsRowsCache  $kept          The compiled case type rows of the last minute.
	 */
	public function __construct(
		public readonly CaseTypeRightsMatrixRows $caseTypes,
		public readonly AssignedGroupReadRows $assignedGroup,
		public readonly CaseTypeRightsRowsCache $kept,
	) {
	}//end __construct()
}//end class
