<?php

/**
 * Procest ConsultationAccess.
 *
 * The outcome of one consultation authorization attempt: either a ready-made
 * error response (401/404/403) or the resolved consultation the caller is
 * allowed to act on. Carrying both in a single value lets a controller
 * collapse the authenticate → load → authorize preamble into one call and one
 * branch, instead of repeating three branches in every endpoint.
 *
 * @category Service
 * @package  OCA\Procest\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Consultation;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Result of a consultation authorization attempt.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class ConsultationAccess {
	/**
	 * Constructor.
	 *
	 * @param JSONResponse|null $error The denial response, or null when authorized.
	 * @param array<string, mixed> $consultation The resolved consultation when authorized.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?JSONResponse $error = null,
		public readonly array $consultation = [],
	) {
	}//end __construct()
}//end class
