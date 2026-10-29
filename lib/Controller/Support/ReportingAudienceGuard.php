<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Dossiq\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * The gate in front of every endpoint that answers a figure about every case.
 *
 * Such an endpoint answers aggregates over cases the caller was never
 * granted, so OpenRegister's per-object refusal never gets a chance to speak.
 * Each one asks this gate first: no session, or a user outside the reporting
 * audience, is refused before anything is read.
 */
trait ReportingAudienceGuard {
	/**
	 * Refuse a caller who may not read a figure about every case.
	 *
	 * @param IUserSession      $userSession The session.
	 * @param ReportingAudience $audience    Who may read a figure about every case.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	private function refuseOutsideReportingAudience(IUserSession $userSession, ReportingAudience $audience): ?JSONResponse {
		$user = $userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		if ($audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudience::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseOutsideReportingAudience()
}
