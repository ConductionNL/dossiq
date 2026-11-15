<?php

/**
 * Dossiq TermExtension.
 *
 * One extension of a case's processing term: the case, why the term is
 * extended and the new end date.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * An extension of a case's processing term.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TermExtension {

	/**
	 * Constructor.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $reason Why the term is extended.
	 * @param string $newEnd The new end date (Y-m-d).
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $reason,
		public readonly string $newEnd,
	) {
	}//end __construct()
}//end class
