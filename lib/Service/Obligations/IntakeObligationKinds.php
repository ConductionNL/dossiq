<?php

/**
 * The obligation kinds a case's type declares, for the intake obligations.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Obligations
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Obligations;

use OCA\Dossiq\Service\CaseTypeResolver;

/**
 * Reads the obligation kinds off the effective case type of a case.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */
class IntakeObligationKinds {

	/**
	 * Constructor.
	 *
	 * @param ObligationDeclaration $declaration Reads the case type's obligation kinds.
	 * @param CaseTypeResolver      $caseTypes   The effective case type of a case.
	 */
	public function __construct(
		private readonly ObligationDeclaration $declaration,
		private readonly CaseTypeResolver $caseTypes,
	) {
	}//end __construct()

	/**
	 * The obligation kinds the case's type declares, keyed by kind.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, array<string, mixed>> Empty for a case without a case type.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
	 */
	public function of(array $case): array {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		return $this->declaration->kindsFor(caseType: $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId));
	}//end of()
}//end class
