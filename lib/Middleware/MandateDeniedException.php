<?php

/**
 * Dossiq Mandate Denied Exception
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use Exception;

/**
 * Mandate matrix denied this request, or its organisation is not active.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */
class MandateDeniedException extends Exception {
	/**
	 * The organisation's lifecycle status, when that is why the request is refused.
	 *
	 * @var string
	 */
	private string $lifecycleStatus = '';

	/**
	 * Mark this refusal as one for an organisation that is not active.
	 *
	 * @param string $status The organisation's lifecycle status.
	 *
	 * @return self The same exception.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function withLifecycleStatus(string $status): self {
		$this->lifecycleStatus = $status;
		return $this;
	}//end withLifecycleStatus()

	/**
	 * The organisation's lifecycle status, or '' when the mandate matrix refused.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getLifecycleStatus(): string {
		return $this->lifecycleStatus;
	}//end getLifecycleStatus()
}//end class
