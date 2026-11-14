<?php

/**
 * The tenant and the month an invoice request is about.
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
 * The tenant and the month an invoice request is about.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class InvoiceMonth {
	/**
	 * Constructor.
	 *
	 * @param string $tenantId Tenant UUID
	 * @param string $period The month, YYYY-MM
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly string $period,
	) {
	}//end __construct()
}//end class
