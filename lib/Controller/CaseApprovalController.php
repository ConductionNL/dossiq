<?php

/**
 * Asking decidiq for the approval a gated act waits on.
 *
 *  - POST /api/case/{caseId}/approvals/{act}   start the walk, link it to the case
 *
 * The walk is decidiq's. This endpoint starts it and records which decidiq
 * decision the act now waits on; the verdict is read back on every evaluation
 * of the act and never stored here.
 *
 * `#[NoAdminRequired]` with the per-case guard first: without it any signed-in
 * user could start an approval on any case by its uuid.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\ApprovalRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts the approval walk for one gated act of one case.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
class CaseApprovalController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The HTTP request.
	 * @param ApprovalRequest $approvals       Starts the walk and records the link.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization, failing closed.
	 * @param IUserSession    $userSession     The current session.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ApprovalRequest $approvals,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask decidiq for the approval this act waits on.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $act    The gated act.
	 *
	 * @return JSONResponse The recorded link, or the refusal.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	#[NoAdminRequired]
	public function raise(string $caseId, string $act): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You do not work on this case.', 'error' => 'not-authorized'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			return new JSONResponse($this->approvals->raise(caseId: $caseId, act: $act, userId: $user->getUID()));
		} catch (RefusedException $e) {
			return $this->refused(op: 'raise approval', e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseApprovalController: the approval could not be asked for',
				['caseId' => $caseId, 'act' => $act, 'exception' => $e->getMessage()],
			);

			return new JSONResponse(
				['message' => 'The approval was not asked for.', 'error' => 'approval-request-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end raise()
}//end class
