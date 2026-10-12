<?php

/**
 * Dossiq Pipelinq Programme Controller.
 *
 * The programme a case hangs under, read and set through pipelinq:
 *
 *  - GET    /api/cases/{caseId}/pipelinq/programme                          the programme the case hangs under
 *  - POST   /api/cases/{caseId}/pipelinq/programme                          put the case under a programme
 *  - GET    /api/pipelinq/programmes                                        the programmes to choose from
 *
 * Split from PipelinqCaseController, which keeps the party kinds and the
 * correspondence language. Why a dossiq endpoint stands in front of pipelinq's
 * own routes is written there.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read and set the programme a case hangs under, through pipelinq.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
class PipelinqProgrammeController extends Controller {

	use PipelinqCaseGuards;

	/**
	 * Constructor.
	 *
	 * @param string            $appName     The app name.
	 * @param IRequest          $request     The HTTP request.
	 * @param ProgrammeConsumer $programmes  The programme above the case.
	 * @param CaseAccessGuard   $access      Per-case authorization (fails closed).
	 * @param IUserSession      $userSession The current session.
	 * @param IL10N             $l10n        The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ProgrammeConsumer $programmes,
		private readonly CaseAccessGuard $access,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The programme this case hangs under, with its progress and mode.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{available, programme}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	#[NoAdminRequired]
	public function programme(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return new JSONResponse($this->programmes->programmeOf(caseId: $caseId));
	}//end programme()

	/**
	 * Put this case under a programme.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $programmeId The programme.
	 * @param string $title       The case title as the handler sees it now.
	 *
	 * @return JSONResponse `{linked: true}`, or 409 naming the programme that already holds it.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	#[NoAdminRequired]
	public function linkProgramme(string $caseId, string $programmeId = '', string $title = ''): JSONResponse {
		if (trim($programmeId) === '') {
			return new JSONResponse(['error' => $this->l10n->t('Choose a programme.')], Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		$outcome = $this->programmes->linkCase(programmeId: $programmeId, caseId: $caseId, title: $title);
		if ($outcome['linked'] === true) {
			return new JSONResponse(['linked' => true]);
		}

		return new JSONResponse(['linked' => false, 'error' => $outcome['reason']], Http::STATUS_CONFLICT);
	}//end linkProgramme()

	/**
	 * The programmes a case may be put under.
	 *
	 * @return JSONResponse `{available, programmes}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
	 */
	#[NoAdminRequired]
	public function programmeOptions(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notSignedIn();
		}

		// Pipelinq reads its own register under the caller's session, so a
		// programme the caller may not see is not offered.
		return new JSONResponse($this->programmes->programmes());
	}//end programmeOptions()
}//end class
