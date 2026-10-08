<?php

/**
 * Dossiq Organisation Not Active Exception
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use Exception;

/**
 * The request's organisation is not active, so dossiq refuses it with 403.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */
class OrganisationNotActiveException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $status The organisation's lifecycle status.
	 */
	public function __construct(
		private readonly string $status,
	) {
		parent::__construct('Organisation is '.$status, 403);
	}//end __construct()

	/**
	 * The organisation's lifecycle status.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()
}//end class
