<?php

/**
 * The case a WOO decision is raised on: its id, its register and the composed record.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The case a WOO decision is raised on: its id, its register and the composed record.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class WooDecisionSubject {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case UUID
	 * @param string $register The register the case lives in
	 * @param array<string, mixed> $record The composed case title and assessment tally
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $register,
		public readonly array $record,
	) {
	}//end __construct()
}//end class
