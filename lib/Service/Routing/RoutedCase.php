<?php

/**
 * Dossiq RoutedCase.
 *
 * A case routed to somebody: the case, who it went to and when.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

/**
 * The case, the person it was routed to, and the moment it was routed.
 *
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */
final class RoutedCase {

	/**
	 * Constructor.
	 *
	 * @param string             $caseId   The case id.
	 * @param string             $routedTo The person it was routed to.
	 * @param \DateTimeImmutable $routedAt When.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $routedTo,
		public readonly \DateTimeImmutable $routedAt,
	) {
	}//end __construct()
}//end class
