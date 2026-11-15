<?php

/**
 * The declarations of a case type that publication checks: acknowledgement, admissibility, remedy clause and handling.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;

/**
 * The declarations of a case type that publication checks: acknowledgement, admissibility, remedy clause and handling.
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */
final class CaseTypeDeclarationChecks {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeAcknowledgement $acknowledgement What this type declares about confirming receipt.
	 * @param AdmissibilityJudgement  $admissibility   Whether intake ends with a verdict, and what it closes on.
	 * @param RemedyClauseDeclaration $remedy          The remedy open against this case type's decisions.
	 * @param CaseTypeHandling        $handling        The one reader of the handling switches.
	 */
	public function __construct(
		public readonly CaseTypeAcknowledgement $acknowledgement,
		public readonly AdmissibilityJudgement $admissibility,
		public readonly RemedyClauseDeclaration $remedy,
		public readonly CaseTypeHandling $handling,
	) {
	}//end __construct()
}//end class
