<?php

/**
 * Dossiq RefusesOutsideReportAudience support trait.
 *
 * The 401 for a caller without a session, the 403 for one outside the
 * reporting audience. The using class provides `$userSession` and
 * `$audience` (ReportingAudienceInterface).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The 401 for a caller without a session, the 403 for one outside the reporting audience.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait RefusesOutsideReportAudience {

	/**
	 * The 401/403 refusal for a caller who is not signed in or not in the reporting audience, else null.
	 *
	 * An aggregate over every record: OpenRegister's per-object refusal
	 * never sees it, so the audience is checked here.
	 *
	 * @return JSONResponse|null
	 */
	private function audienceRefusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->audience->isInAudience(user: $user) === false) {
			return new JSONResponse(['message' => ReportingAudienceInterface::REFUSAL], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end audienceRefusal()
}//end trait
