<?php

/**
 * Dossiq CaseEnrichmentBasis.
 *
 * What every case of one enrichment run is read against: the case types and the reference day.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Doorlooptijd
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
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Doorlooptijd;

/**
 * The case types by id and slug, and the reference date for the countdown.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class CaseEnrichmentBasis {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $caseTypeByKey Case-types by id and slug.
	 * @param \DateTimeImmutable   $today         Reference date for the countdown.
	 */
	public function __construct(
		public readonly array $caseTypeByKey,
		public readonly \DateTimeImmutable $today,
	) {
	}//end __construct()
}//end class
