<?php

/**
 * Dossiq Pipelinq Contact Moment Controller.
 *
 * The contact moments of a case, read and acted on through pipelinq:
 *
 *  - POST   /api/cases/{caseId}/pipelinq/contact-moments                    log one, and say if pipelinq refused it
 *  - GET    /api/cases/{caseId}/pipelinq/contact-moments                    by membership, with the shared marker
 *  - POST   /api/cases/{caseId}/pipelinq/contact-moments/{momentId}/file    file it on another case too
 *  - DELETE /api/cases/{caseId}/pipelinq/contact-moments/{momentId}         take it off this case
 *
 * Split from PipelinqCaseController, which keeps the party kinds, the
 * correspondence language and the programme. Why a dossiq endpoint stands in
 * front of pipelinq's own routes is written there.
 *
 * Every route checks the caller against the case first (CaseAccessGuard, fail
 * closed). Filing onto another case needs mutation access on BOTH cases:
 * putting a call on a case is a change to that case.
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
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Read and act on a case's contact moments through pipelinq.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
class PipelinqContactMomentController extends Controller {

	use PipelinqCaseGuards;

	/**
	 * Constructor.
	 *
	 * @param string               $appName     The app name.
	 * @param IRequest             $request     The HTTP request.
	 * @param ContactMomentBridge  $moments     Contact moments by membership, and the two filing acts.
	 * @param ContactMomentService $log         Writes the dossiq contact moment and appends it to pipelinq.
	 * @param CaseAccessGuard      $access      Per-case authorization (fails closed).
	 * @param IUserSession         $userSession The current session.
	 * @param IL10N                $l10n        The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContactMomentBridge $moments,
		private readonly ContactMomentService $log,
		private readonly CaseAccessGuard $access,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The contact moments this case is a member of, with the shared marker.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{available, moments, indicators}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	#[NoAdminRequired]
	public function contactMoments(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return new JSONResponse($this->moments->onCase(caseId: $caseId));
	}//end contactMoments()

	/**
	 * Log a contact moment on this case, and say when pipelinq refused it.
	 *
	 * The Log contact dialog on the case page posts here rather than to the
	 * object API, because only ContactMomentService appends the moment to
	 * pipelinq, and only its answer can carry pipelinq's refusal back to the
	 * handler who just typed it. The dossiq record is written first and is kept
	 * whatever pipelinq says.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{contactmoment, pipelinqRefusal, pipelinqIndicators}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
	 */
	#[NoAdminRequired]
	public function logContactMoment(string $caseId): JSONResponse {
		$refused = $this->refuseUnlessEditor(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
		}

		$data = [
			'case' => $caseId,
			'relatedCases' => [$caseId],
			'notificationChannel' => (string)$this->request->getParam('notificationChannel', ''),
			'direction' => (string)$this->request->getParam('direction', 'inbound'),
			'summary' => (string)$this->request->getParam('summary', ''),
			'callerIdentification' => (string)$this->request->getParam('callerIdentification', ''),
			'visibleToApplicant' => ($this->request->getParam('visibleToApplicant', false) === true),
		];

		$startTime = trim((string)$this->request->getParam('startTime', ''));
		if ($startTime !== '') {
			$data['startTime'] = $startTime;
		}

		try {
			$record = $this->log->createContactMoment(data: $data);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['error' => $this->l10n->t('The contact moment could not be saved: %s', [$e->getMessage()])],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			[
				'contactmoment' => $record,
				'pipelinqRefusal' => (string)($record['pipelinqRefusal'] ?? ''),
				'pipelinqIndicators' => (array)($record['pipelinqIndicators'] ?? []),
			],
			Http::STATUS_CREATED
		);
	}//end logContactMoment()

	/**
	 * File a contact moment on this case onto a further case, through pipelinq.
	 *
	 * @param string $caseId       The case the moment is on now.
	 * @param string $momentId     The contact moment.
	 * @param string $targetCaseId The case to file it onto.
	 *
	 * @return JSONResponse `{filed: true}`, or the refusal with pipelinq's reason.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	#[NoAdminRequired]
	public function fileContactMoment(string $caseId, string $momentId, string $targetCaseId = ''): JSONResponse {
		$targetCaseId = trim($targetCaseId);
		if ($targetCaseId === '' || $targetCaseId === trim($caseId)) {
			return new JSONResponse(
				['error' => $this->l10n->t('Choose another case to file this contact moment on.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if (
			$this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false
			|| $this->access->hasCaseMutationAccess(caseId: $targetCaseId, user: $user) === false
		) {
			return $this->forbidden();
		}

		return $this->act(outcome: $this->moments->fileOnAlsoCase(momentId: $momentId, caseId: $targetCaseId));
	}//end fileContactMoment()

	/**
	 * Take a contact moment off this case, through pipelinq.
	 *
	 * @param string $caseId   The case.
	 * @param string $momentId The contact moment.
	 *
	 * @return JSONResponse `{filed: true}`, or the refusal with pipelinq's reason.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	#[NoAdminRequired]
	public function unfileContactMoment(string $caseId, string $momentId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return $this->act(outcome: $this->moments->unfileFromCase(momentId: $momentId, caseId: $caseId));
	}//end unfileContactMoment()

	/**
	 * Answer a filing act: done, or refused with pipelinq's reason.
	 *
	 * @param array{filed: bool, reason: string} $outcome The act's outcome.
	 *
	 * @return JSONResponse
	 */
	private function act(array $outcome): JSONResponse {
		if ($outcome['filed'] === true) {
			return new JSONResponse(['filed' => true]);
		}

		return new JSONResponse(['filed' => false, 'error' => $outcome['reason']], Http::STATUS_CONFLICT);
	}//end act()
}//end class
