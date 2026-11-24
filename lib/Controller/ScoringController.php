<?php

/**
 * Dossiq ScoringController.
 *
 * `GET /api/cases/{caseId}/scoring`: the scoring sheet of a case, for a reader.
 * `POST /api/cases/{caseId}/scoring`: score one submission with the case's
 * formula and store the caller's evaluation, for a handler who may change the
 * case. The case page's Score bid dialog reads and posts these.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ScoringSheetInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The scoring sheet of a case and the scoring of one of its submissions.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
class ScoringController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param ScoringSheetInterface    $sheet       The scoring sheet.
	 * @param IUserSession             $userSession The session.
	 * @param CaseAccessGuardInterface $caseAccess  Decides who may read or change the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ScoringSheetInterface $sheet,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The scoring sheet of the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse 200 with the sheet, 403 for a non-reader, 503 when the register fails.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	#[NoAdminRequired]
	public function sheet(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->sheet->forCase(caseId: $caseId, userId: $user->getUID()));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The scoring sheet could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end sheet()

	/**
	 * Score one submission of the case.
	 *
	 * Body: `{bid: <uuid>, scores: {<input>: <number>}}`. The evaluator is the
	 * session user, never a body field.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse 200 `{evaluationId, total}`, 422 `{message}` with the refusal,
	 *                      403 for a user who may not change the case, 503 when the register fails.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	#[NoAdminRequired]
	public function score(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not change this case.'], Http::STATUS_FORBIDDEN);
		}

		$scores = $this->request->getParam('scores', []);
		if (is_array($scores) === false) {
			$scores = [];
		}

		try {
			$result = $this->sheet->score(
				caseId: $caseId,
				submissionId: trim((string)$this->request->getParam('bid', '')),
				scores: $scores,
				userId: $user->getUID()
			);
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The score could not be stored. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if (isset($result['refusal']) === true) {
			return new JSONResponse(['message' => $result['refusal']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result);
	}//end score()
}//end class
