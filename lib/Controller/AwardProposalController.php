<?php

/**
 * Dossiq AwardProposalController.
 *
 * `POST /api/cases/{caseId}/award-proposal`: propose one admissible bid of the
 * case for the award of its lot. The case page's Propose award action posts
 * it; the bid holds the proposal and the case's flow makes the award final
 * after its standstill term.
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\AwardProposalInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The proposed award of a case, for someone who may change the case.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
 */
class AwardProposalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param AwardProposalInterface   $proposal    Holds the proposal on the bids.
	 * @param IUserSession             $userSession The user session.
	 * @param CaseAccessGuardInterface $caseAccess  Per-case authorization guard.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AwardProposalInterface $proposal,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Propose the bid the body names for the award of its lot.
	 *
	 * Body: `{bid: <uuid>}`. A case the user may not change answers 403 before
	 * anything is read; a bid that is not an admissible bid of the case
	 * answers 422 with the reason.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse 200 `{proposed, released}`, 403, 422 `{message}`, or 503.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#requirement-a-tender-is-worked-end-to-end-on-its-case-page-req-tnd-case-01
	 */
	#[NoAdminRequired]
	public function propose(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not change this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->proposal->propose(caseId: $caseId, submissionId: trim((string)$this->request->getParam('bid', '')));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The award could not be proposed. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if (isset($result['refusal']) === true) {
			return new JSONResponse(['message' => $result['refusal']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result);
	}//end propose()
}//end class
