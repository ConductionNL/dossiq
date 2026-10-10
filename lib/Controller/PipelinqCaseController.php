<?php

/**
 * Dossiq Pipelinq Case Controller.
 *
 * The case surfaces that read and act through pipelinq, each over the consumer
 * that already holds the rule:
 *
 *  - POST   /api/cases/{caseId}/pipelinq/contact-moments                    log one, and say if pipelinq refused it
 *  - GET    /api/cases/{caseId}/pipelinq/contact-moments                    by membership, with the shared marker
 *  - POST   /api/cases/{caseId}/pipelinq/contact-moments/{momentId}/file    file it on another case too
 *  - DELETE /api/cases/{caseId}/pipelinq/contact-moments/{momentId}         take it off this case
 *  - GET    /api/cases/{caseId}/pipelinq/party-kinds                        the kinds this case's type accepts
 *  - GET    /api/cases/{caseId}/pipelinq/parties/{partyId}/language         the language to write to a party in
 *  - GET    /api/cases/{caseId}/pipelinq/programme                          the programme the case hangs under
 *  - POST   /api/cases/{caseId}/pipelinq/programme                          put the case under a programme
 *  - GET    /api/pipelinq/programmes                                        the programmes to choose from
 *  - GET    /api/case-types/{caseTypeId}/pipelinq/party-kinds               the vocabulary and the declaration
 *  - PUT    /api/case-types/{caseTypeId}/pipelinq/party-kinds               declare the accepted kinds
 *
 * WHY A DOSSIQ ENDPOINT AND NOT PIPELINQ'S OWN ROUTES. The consumers carry the
 * rules this change exists for: an absent pipelinq answered apart from an empty
 * one, an uncomputable figure kept from becoming zero, the acceptance written
 * as a patch rather than a replace, and the shared marker counting what the
 * reader may not see. A browser calling pipelinq directly would have to
 * reimplement each of them, and the consumers would stay without a caller.
 *
 * Every case route checks the caller against the case first (CaseAccessGuard,
 * fail closed). Filing onto another case needs mutation access on BOTH cases:
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
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Read and act through pipelinq on a case, over the consumers.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
class PipelinqCaseController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName     The app name.
	 * @param IRequest                       $request     The HTTP request.
	 * @param PipelinqGateway                $gateway     Whether pipelinq is here at all.
	 * @param ContactMomentBridge            $moments     Contact moments by membership, and the two filing acts.
	 * @param ContactMomentService           $log         Writes the dossiq contact moment and appends it to pipelinq.
	 * @param PartyKindConsumer              $kinds       The kinds and the per-case-type acceptance.
	 * @param CorrespondenceLanguageConsumer $languages   The language to write in, with its reason.
	 * @param ProgrammeConsumer              $programmes  The programme above the case.
	 * @param MilestoneRepository            $cases       Reads a case's type without the client supplying it.
	 * @param CaseAccessGuard                $access      Per-case authorization (fails closed).
	 * @param IUserSession                   $userSession The current session.
	 * @param IL10N                          $l10n        The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PipelinqGateway $gateway,
		private readonly ContactMomentBridge $moments,
		private readonly ContactMomentService $log,
		private readonly PartyKindConsumer $kinds,
		private readonly CorrespondenceLanguageConsumer $languages,
		private readonly ProgrammeConsumer $programmes,
		private readonly MilestoneRepository $cases,
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
		$refused = $this->refuseUnlessReader(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
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

		$refused = $this->refuseUnlessEditor(caseId: $caseId);
		if ($refused === null) {
			$refused = $this->refuseUnlessEditor(caseId: $targetCaseId);
		}

		if ($refused !== null) {
			return $refused;
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
		$refused = $this->refuseUnlessEditor(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
		}

		return $this->act(outcome: $this->moments->unfileFromCase(momentId: $momentId, caseId: $caseId));
	}//end unfileContactMoment()

	/**
	 * The party kinds this case's type accepts, in order, and who answered.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse `{source, kinds, available}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	#[NoAdminRequired]
	public function partyKinds(string $caseId): JSONResponse {
		$refused = $this->refuseUnlessReader(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
		}

		$caseType = (string)($this->cases->findCaseTypeId(caseId: $caseId) ?? '');
		if ($caseType === '') {
			// No type to ask about: dossiq's own kinds, said as dossiq's.
			return new JSONResponse(['source' => 'dossiq', 'kinds' => $this->kinds->vocabulary()['kinds'], 'available' => $this->gateway->isAvailable()]);
		}

		return new JSONResponse($this->kinds->kindsFor(caseType: $caseType) + ['available' => $this->gateway->isAvailable()]);
	}//end partyKinds()

	/**
	 * The language to write to one party of this case in, with its reason.
	 *
	 * @param string $caseId  The case UUID.
	 * @param string $partyId The party UUID.
	 *
	 * @return JSONResponse `{available, language, rule, stated, sentence}`.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-language-to-write-to-a-party-in-comes-from-the-resolver-with-its-reason-req-plq-06
	 */
	#[NoAdminRequired]
	public function partyLanguage(string $caseId, string $partyId): JSONResponse {
		$refused = $this->refuseUnlessReader(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
		}

		return new JSONResponse($this->languages->forParty(partyId: $partyId));
	}//end partyLanguage()

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
		$refused = $this->refuseUnlessReader(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
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

		$refused = $this->refuseUnlessEditor(caseId: $caseId);
		if ($refused !== null) {
			return $refused;
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

		// pipelinq reads its own register under the caller's session, so a
		// programme the caller may not see is not offered.
		return new JSONResponse($this->programmes->programmes());
	}//end programmeOptions()

	/**
	 * The kind vocabulary and what one case type has declared, for the editor.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return JSONResponse `{source, kinds, accepted, available}`; `accepted` null means none declared.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	#[NoAdminRequired]
	public function caseTypePartyKinds(string $caseTypeId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notSignedIn();
		}

		$vocabulary = $this->kinds->vocabulary();

		return new JSONResponse(
			[
				'source' => $vocabulary['source'],
				'kinds' => $vocabulary['kinds'],
				'accepted' => $this->kinds->acceptanceOf(caseType: $caseTypeId),
				'available' => $this->gateway->isAvailable(),
			]
		);
	}//end caseTypePartyKinds()

	/**
	 * Declare which party kinds a case type accepts, in order.
	 *
	 * @param string             $caseTypeId The case type UUID.
	 * @param array<int, string> $kinds      The accepted kind codes, in order.
	 *
	 * @return JSONResponse `{declared: true}`, or the reason it was not written.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function declarePartyKinds(string $caseTypeId, array $kinds = []): JSONResponse {
		$outcome = $this->kinds->declareAcceptance(caseType: $caseTypeId, kinds: $kinds);
		if ($outcome['declared'] === true) {
			return new JSONResponse(['declared' => true, 'accepted' => $this->kinds->acceptanceOf(caseType: $caseTypeId)]);
		}

		return new JSONResponse(['declared' => false, 'error' => $outcome['reason']], Http::STATUS_CONFLICT);
	}//end declarePartyKinds()

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

	/**
	 * Refuse a caller who may not read this case; null when they may.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function refuseUnlessReader(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return null;
	}//end refuseUnlessReader()

	/**
	 * Refuse a caller who may not change this case; null when they may.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function refuseUnlessEditor(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->access->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return null;
	}//end refuseUnlessEditor()

	/**
	 * The answer for a caller with no session.
	 *
	 * @return JSONResponse
	 */
	private function notSignedIn(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('You are not signed in.')], Http::STATUS_UNAUTHORIZED);
	}//end notSignedIn()

	/**
	 * The answer for a caller without access to the case.
	 *
	 * @return JSONResponse
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => $this->l10n->t('You do not have access to this case.')], Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end class
