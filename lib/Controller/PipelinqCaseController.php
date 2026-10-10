<?php

/**
 * Dossiq Pipelinq Case Controller.
 *
 * The case surfaces that read and act through pipelinq, each over the consumer
 * that already holds the rule (contact moments and the programme have their own
 * controllers, PipelinqContactMomentController and PipelinqProgrammeController):
 *
 *  - GET    /api/cases/{caseId}/pipelinq/party-kinds                        the kinds this case's type accepts
 *  - GET    /api/cases/{caseId}/pipelinq/parties/{partyId}/language         the language to write to a party in
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
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read and act through pipelinq on a case, over the consumers.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
class PipelinqCaseController extends Controller {

	use PipelinqCaseGuards;

	/**
	 * Constructor.
	 *
	 * @param string                         $appName     The app name.
	 * @param IRequest                       $request     The HTTP request.
	 * @param PipelinqGateway                $gateway     Whether pipelinq is here at all.
	 * @param PartyKindConsumer              $kinds       The kinds and the per-case-type acceptance.
	 * @param CorrespondenceLanguageConsumer $languages   The language to write in, with its reason.
	 * @param MilestoneRepository            $cases       Reads a case's type without the client supplying it.
	 * @param CaseAccessGuard                $access      Per-case authorization (fails closed).
	 * @param IUserSession                   $userSession The current session.
	 * @param IL10N                          $l10n        The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PipelinqGateway $gateway,
		private readonly PartyKindConsumer $kinds,
		private readonly CorrespondenceLanguageConsumer $languages,
		private readonly MilestoneRepository $cases,
		private readonly CaseAccessGuard $access,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

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
}//end class
