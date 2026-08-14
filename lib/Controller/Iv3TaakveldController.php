<?php

/**
 * Procest IV3 Taakveld Controller
 *
 * Serves the IV3/BBV taakveld reference list to the case-type settings
 * picker, and nothing else.
 *
 * What used to live here — the quarterly IV3 cost report — is gone under
 * ADR-081: Shillinq is the fleet's only general ledger and only statutory
 * reporter, and procest MUST NOT sum money or emit a statutory report. What
 * procest keeps is the CLASSIFICATION (`caseType.iv3Taakveld`), which is
 * exactly what this endpoint exists to populate.
 *
 * The list itself is still procest's for now. ADR-081 decision 1 makes
 * Shillinq's `BbvTaakveld` catalogue the single taakveld authority, and this
 * endpoint is the seam that will read from it — but Shillinq does not expose
 * it cross-app yet, and deleting the local list before that would break the
 * classification field the ADR explicitly keeps. Sequencing, not an exception:
 * the report goes now because it was dead; the list follows the catalogue.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\Iv3TaakveldList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only access to the IV3/BBV taakveld reference list.
 */
class Iv3TaakveldController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param Iv3TaakveldList $taskFieldList Taakveld reference list.
	 * @param IUserSession $userSession Current user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Iv3TaakveldList $taskFieldList,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The IV3 taakveld reference list — open to any authenticated user (it
	 * is a public CBS classification, not report data).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function taakvelden(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'version' => $this->taskFieldList->version(),
				'taakvelden' => $this->taskFieldList->allTaakvelden(),
			]
		);
	}//end taakvelden()
}//end class
