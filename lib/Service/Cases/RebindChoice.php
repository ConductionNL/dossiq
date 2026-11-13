<?php

/**
 * Dossiq RebindChoice.
 *
 * What the coordinator chose for one rebind: the target case type, the
 * landing status, the remaps and the answers given in the dialog.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * The choice CaseRebindImpact computes the impact of.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class RebindChoice {
	/**
	 * Constructor.
	 *
	 * @param string                $caseTypeId The target case type.
	 * @param string                $statusId   The landing status, or '' when none is chosen yet.
	 * @param array<string, string> $remap      Source answer name => target field name.
	 * @param array<string, mixed>  $answers    Target field name => answer.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseTypeId,
		public readonly string $statusId,
		public readonly array $remap,
		public readonly array $answers,
	) {
	}//end __construct()
}//end class
