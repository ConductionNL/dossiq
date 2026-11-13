<?php

/**
 * The notices of default on one case (DqZaak, Fees and payments tab).
 *
 * The deadlines report lists every notice for the reporting audience. A
 * handler on the case needs the notices of that case only, read with the
 * case's own access: whoever may read the case may read its notices.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Answers the notices of default on one case.
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
 */
class CaseNoticesOfDefaultController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName  The app name.
	 * @param IRequest            $request  The request.
	 * @param NoticeOfDefaultRows $notices  The notice rows.
	 * @param ReadableObjects     $readable Whether a caller is signed in and may read the case.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NoticeOfDefaultRows $notices,
		private readonly ReadableObjects $readable,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The notices of default on a case, newest first.
	 *
	 * The case is first read with the caller's own rights; a case the caller
	 * may not read answers 404, the same as a missing one, and its notices are
	 * never read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The notices, or the refusal.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read this case.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			if ($this->readable->findsAsCaller(schema: 'case', id: $caseId) === false) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->notices->forCase(caseId: $caseId));
		} catch (Throwable) {
			return new JSONResponse(
				['error' => 'The notices of default could not be read. Try again later.'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end index()
}//end class
