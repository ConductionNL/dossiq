<?php

/**
 * Dossiq Woo request intake
 *
 * THE ONE PATH A WOO REQUEST IS OPENED BY (hydra woo-citizen-journey, C5). A
 * resident starting a request from their dossier on the portal and a KCC
 * employee converting a question in pipelinq both land here, so the two can
 * never open different kinds of case. pipelinq reaches this class by name
 * through the container (duck-typed, no hard dependency); dossiq's portal
 * receiver calls it after verifying portaliq's assertion.
 *
 * The caller is never a Nextcloud user with rights on these records: it is a
 * portal subject or a service call. A portal forward even arrives with no
 * Nextcloud user at all, and OpenRegister then scopes a plain read to nobody
 * (the dossier looked missing to every resident). So every read and write
 * runs as the system AND unscoped (`_rbac: false, _multitenancy: false`) on a
 * register and schema this class pins itself, and the one authorization is
 * the ownership check on the dossier:
 * its `owner` must be the `subjectRef` the request names. A dossier that does
 * not exist and one that belongs to someone else give the same refusal, so
 * nobody can probe which ids exist.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\Intake\CollectionCaseLinks;
use OCA\Dossiq\Service\Intake\CollectionLinkQueue;
use OCA\Dossiq\Service\Intake\SystemCaseStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Opens a Woo request case for a resident, optionally from their dossier.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
 */
class WooRequestIntake {

	/**
	 * The seeded Woo request case type (register.d/81-woo-verzoek.json).
	 */
	public const CASE_TYPE_ID = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * The identifier every version of the Woo request case type shares (the
	 * version chain is read by identifier).
	 *
	 * @var string
	 */
	public const CASE_TYPE_IDENTIFIER = 'woo-verzoek';

	/**
	 * The case object type of a publication from a dossier.
	 */
	public const OBJECT_TYPE = CollectionCaseLinks::OBJECT_TYPE;

	/**
	 * Where a request may come from.
	 */
	public const ORIGINS = ['portal', 'pipelinq', 'portal-form', 'opencatalogi'];

	/**
	 * The origins receive() takes, which need no resident subject: an
	 * anonymous portal form, and a request opencatalogi took in (REQ-WTO-001).
	 */
	public const SUBJECTLESS_ORIGINS = ['portal-form', 'opencatalogi'];

	/**
	 * The prefix of the reference a dossier keeps in `sourceOf`.
	 */
	public const SOURCE_PREFIX = CollectionCaseLinks::SOURCE_PREFIX;

	/**
	 * The case's `intakeChannel` per origin, in the case schema's own enum.
	 * The origin itself is kept in `wooRequest.origin`.
	 */
	public const INTAKE_CHANNEL = [
		'portal' => 'website',
		'pipelinq' => 'other',
		'portal-form' => 'website',
		'opencatalogi' => 'other',
	];

	/**
	 * How a portal subject logged in, per audience of portaliq's verified
	 * assertion, in the case schema's `intakeIdentification` enum: a resident
	 * (`citizen`, `client`) logs in with DigiD, a business with eHerkenning.
	 */
	public const IDENTIFICATION_BY_AUDIENCE = [
		'citizen' => 'digid',
		'client' => 'digid',
		'business' => 'eherkenning',
	];

	/**
	 * Reads the case type and the case, and writes the case, as the system.
	 *
	 * @var SystemCaseStore
	 */
	private readonly SystemCaseStore $cases;

	/**
	 * Reads the resident's own dossier and links it to the case.
	 *
	 * @var CollectionCaseLinks
	 */
	private readonly CollectionCaseLinks $collections;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 * @param IURLGenerator   $urlGenerator    Makes the publication and case links absolute.
	 * @param LoggerInterface $logger          Logger.
	 * @param WooReceivedTerm|null $receivedTerm Reads back the term a received case got. Null
	 *                                           only where receive() is never called.
	 * @param WooReceivedAnswers|null $receivedAnswers Maps the received answers, and reads when the
	 *                                                 requester sent it. Null only where receive()
	 *                                                 is never called.
	 * @param SystemCaseStore|null $cases Writes the case as the system and announces it; the
	 *                                    container hands it the announcer. A store that cannot
	 *                                    announce when null.
	 * @param CollectionLinkQueue|null $linkQueue Links a dossier to its case after the case is
	 *                                            answered (REQ-PSM-002); linked inline when null.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ?WooReceivedTerm $receivedTerm = null,
		private readonly ?WooReceivedAnswers $receivedAnswers = null,
		?SystemCaseStore $cases = null,
		private readonly ?CollectionLinkQueue $linkQueue = null,
	) {
		$this->cases       = ($cases ?? new SystemCaseStore(settingsService: $settingsService, logger: $logger));
		$this->collections = new CollectionCaseLinks(settingsService: $settingsService, urlGenerator: $urlGenerator, logger: $logger);
	}//end __construct()

	/**
	 * Open a Woo request.
	 *
	 * @param array<string, mixed> $request `{subjectRef, collectionId?, onderwerp, omschrijving,
	 *                                      periodeVan, periodeTot, origin, originReference}`.
	 *
	 * @return array{caseId: string, caseUrl: string, identifier?: string, deadline?: string}
	 *
	 * @throws WooRequestRefused When the request is unusable, the dossier belongs to someone else,
	 *                           or the register or case type is missing.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	public function start(array $request): array {
		$wooRequest = (new WooRequestForm())->normalise(request: $request);
		$subjectRef = trim((string)$request['subjectRef']);

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'OpenRegister or the dossiq register is missing.');
		}

		$collection = null;
		if ($wooRequest['collectionId'] !== '') {
			$collection = $this->collections->owned(objectService: $objectService, collectionId: $wooRequest['collectionId'], subjectRef: $subjectRef);
			if ($collection === null) {
				throw new WooRequestRefused(WooRequestRefused::NOT_FOUND, 'No such dossier.');
			}
		}

		$caseType = $this->caseType(objectService: $objectService, register: $register);
		$saved = $this->writeCase(
			objectService: $objectService,
			register: $register,
			caseType: $caseType,
			wooRequest: $wooRequest,
			intake: [
				// No startDate: dossiq sets it inside the create, from the
				// receipt and the term start (decision 181, Q11), so a request
				// sent on Sunday does not count its P28D from Sunday.
				'portalSubject' => $subjectRef,
				// THE PARTY THE CASE BELONGS TO, typed as portaliq's mandates name
				// it. The assertion carries the subject only, so a request is the
				// person's own until portaliq signs a company or a mandate into it
				// (site-business-and-authorisation D2). Never a BSN.
				'portalParty' => 'subject:' . $subjectRef,
				'intakeChannel' => self::INTAKE_CHANNEL[(string)$wooRequest['origin']],
			] + $this->identification(request: $request),
			startFrom: trim((string)($request['startFrom'] ?? '')),
			branch: $this->branchOf(request: $request),
		);
		$caseId = $saved['id'];

		if ($collection !== null) {
			$this->linkLater(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection, subjectRef: $subjectRef);
		}

		// THE CASE NUMBER AND THE DATE, READ BACK FROM WHAT WAS WRITTEN, so
		// the confirmation names the case the resident can look up and not a
		// number this method made up (site-woo-request-in-steps D4). A case
		// whose `deadline` is not computed yet leaves the key out rather than
		// answering an empty value, and the confirmation then drops that
		// sentence instead of promising a date of ''.
		return [
			'caseId' => $caseId,
			'caseUrl' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/dossiq/cases/' . $caseId),
		] + $saved['answer'];
	}//end start()

	/**
	 * How the requester logged in and the portal the request came through,
	 * for a request from the portal only: the means from the audience the
	 * receiver read off the verified assertion, the name from portaliq's
	 * config. An unknown audience and an empty name are left out.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array{intakeIdentification?: string, intakeSourceName?: string}
	 *
	 * @spec openspec/specs/requester-identified-at-intake/spec.md#requirement-a-portal-intake-records-how-the-requester-logged-in-and-where
	 */
	private function identification(array $request): array {
		if (($request['origin'] ?? '') !== 'portal') {
			return [];
		}

		$out = [];
		$means = (self::IDENTIFICATION_BY_AUDIENCE[(string)($request['audience'] ?? '')] ?? '');
		if ($means !== '') {
			$out['intakeIdentification'] = $means;
		}

		$source = $this->settingsService->getPortalName();
		if ($source !== '') {
			$out['intakeSourceName'] = $source;
		}

		return $out;
	}//end identification()

	/**
	 * Link the dossier to the case after the case is answered.
	 *
	 * The link is one read and one write per dossier item plus a write on the
	 * dossier; done before answering, it kept the portal's forward waiting
	 * past its timeout while the case already existed. A background job does
	 * it right after; without a job list it is done here, as before.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The dossier, already proven the resident's.
	 * @param string               $subjectRef    The resident.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	private function linkLater(object $objectService, string $register, string $caseId, array $collection, string $subjectRef): void {
		if ($this->linkQueue === null) {
			$this->collections->link(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection);
			return;
		}

		$this->linkQueue->queue(register: $register, caseId: $caseId, collectionId: (string)$collection['id'], subjectRef: $subjectRef);
	}//end linkLater()

	/**
	 * Receive a Woo request in opencatalogi's shape and open its case.
	 *
	 * The form submit creates the case directly (decision 179): nothing is
	 * queued in between, and a request that cannot become a valid case is
	 * refused here with nothing written.
	 *
	 * @param array<string, mixed> $answers    `{requestedInformation, requesterName?, requesterEmail?,
	 *                                         requesterPhone?, requesterAddress?, channel?, subjectRef?,
	 *                                         originReference?}`.
	 * @param string               $receivedAt When the requester sent it (ISO 8601); now when empty or unreadable.
	 * @param string               $origin     `portal-form` or `opencatalogi`.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array {
		try {
			$answersMap = $this->receivedAnswers;
			if ($answersMap === null) {
				throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The date reader is not available.');
			}

			$wooRequest = (new WooRequestForm())->normalise(request: $answersMap->toRequest(answers: $answers, origin: $origin));
			$intake = $answersMap->intake(answers: $answers, origin: $origin, receivedAt: $receivedAt);

			$objectService = $this->settingsService->getObjectService();
			$register = $this->settingsService->getConfigValue('register');
			if ($objectService === null || $register === '') {
				throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'OpenRegister or the dossiq register is missing.');
			}

			// NO TERM ENGINE, NO CASE. A Woo request without a running
			// statutory term is the state this intake exists to prevent, so
			// it is refused before anything is written (REQ-WTO-002).
			$receivedTerm = $this->receivedTerm;
			if ($receivedTerm === null || $receivedTerm->isAvailable() === false) {
				throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The term engine is not available.');
			}

			$caseType = $this->caseType(objectService: $objectService, register: $register);
			$saved = $this->writeCase(objectService: $objectService, register: $register, caseType: $caseType, wooRequest: $wooRequest, intake: $intake);
		} catch (WooRequestRefused $e) {
			// An unavailable dependency is `unavailable`; every other refusal is `refused`.
			$outcome = ([WooRequestRefused::UNAVAILABLE => 'unavailable'][$e->getReason()] ?? 'refused');

			return array_merge(WooReceivedAnswers::EMPTY_ANSWER, ['outcome' => $outcome, 'message' => $e->getDetail()]);
		}//end try

		return $this->termAnswer(receivedTerm: $receivedTerm, objectService: $objectService, register: $register, caseId: $saved['id']);
	}//end receive()

	/**
	 * Armed only when the written case's statutory term runs.
	 *
	 * Read back, never assumed: the case must have a statutory term instance,
	 * that instance an engine timer, and the case `deadline` must be the
	 * instance's rolled `endDateCurrent`. Anything less answers `not-armed`
	 * and leaves an internal timeline entry on the case saying why.
	 *
	 * @param WooReceivedTerm $receivedTerm  Reads the term back.
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param string          $register      The dossiq register.
	 * @param string          $caseId        The written case.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	private function termAnswer(WooReceivedTerm $receivedTerm, object $objectService, string $register, string $caseId): array {
		return $receivedTerm->answer(
			caseId: $caseId,
			case: $this->cases->readCase(objectService: $objectService, register: $register, caseId: $caseId),
			caseUrl: $this->urlGenerator->getAbsoluteURL('/index.php/apps/dossiq/cases/' . $caseId)
		);
	}//end termAnswer()

	/**
	 * The version of the seeded Woo request case type that new cases get.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WooRequestRefused UNAVAILABLE when it is not there.
	 */
	private function caseType(object $objectService, string $register): array {
		$caseType = $this->cases->currentCaseType(objectService: $objectService, register: $register, caseTypeId: self::CASE_TYPE_ID);
		if ($caseType === null) {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The Woo request case type is not installed.');
		}

		return $caseType;
	}//end caseType()

	/**
	 * The branch number a request carries, or '' when it carries none or no
	 * twelve-digit vestigingsnummer.
	 *
	 * @param array<string, mixed> $request The request as it came in.
	 *
	 * @return string The branch, or ''.
	 */
	private function branchOf(array $request): string {
		$value  = ($request['branch'] ?? null);
		$branch = '';
		if (is_scalar($value) === true) {
			$branch = trim((string)$value);
		}

		if (preg_match('/^[0-9]{12}$/', $branch) !== 1) {
			return '';
		}

		return $branch;
	}//end branchOf()

	/**
	 * Write the case and answer its uuid.
	 *
	 * @param object                $objectService The OpenRegister ObjectService.
	 * @param string                $register      The dossiq register.
	 * @param array<string, mixed>  $caseType      The Woo request case type.
	 * @param array<string, string> $wooRequest    The request as the case keeps it.
	 * @param array<string, string> $intake        How it came in: `startDate`, `intakeChannel`,
	 *                                             and when known `portalSubject` and `receivedAt`.
	 * @param string                $startFrom     An earlier Woo case this request starts from, or ''.
	 * @param string                $branch        The branch number a company filed under, or ''.
	 *
	 * @return array{id: string, answer: array<string, string>} The case uuid, and the number and date it carries.
	 *
	 * @throws WooRequestRefused UNAVAILABLE when the write fails.
	 *
	 * @spec openspec/changes/a-portal-request-names-its-requester/specs/woo-request-intake/spec.md#requirement-a-case-filed-for-a-resident-names-who-asked-req-wri-006
	 */
	private function writeCase(
		object $objectService,
		string $register,
		array $caseType,
		array $wooRequest,
		array $intake,
		string $startFrom='',
		string $branch='',
	): array {
		$case = [
			'title' => $wooRequest['onderwerp'],
			'description' => $wooRequest['omschrijving'],
			'caseType' => (string)($caseType['id'] ?? self::CASE_TYPE_ID),
		] + $intake + ['wooRequest' => $wooRequest];

		// A request that starts from an earlier one names it; WooStartFromListener
		// copies that request's configuration once the case exists (REQ-WRC-005).
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $startFrom) === 1) {
			$case['wooStartFrom'] = $startFrom;
		}

		// THE BRANCH A COMPANY FILED UNDER, so a session restricted to that
		// branch lists the case (portal-case-list-declarations D3).
		if ($branch !== '') {
			$case['portalBranch'] = $branch;
		}

		// THE REQUESTER DETAILS ALSO ANSWER THE CASE TYPE'S OWN QUESTIONS.
		// The Woo type declares verzoekerNaam, verzoekerEmail and
		// verzoekerType as property definitions, which is where a handler
		// reads them on the case screen; `wooRequest` is where the request as
		// sent is kept. Both, because they answer different questions: what
		// this case knows about its requester, and what the resident filled in
		// at the time (site-woo-request-in-steps REQ-SWS-010).
		$properties = (new WooRequesterProperties(
			settingsService: $this->settingsService,
			logger: $this->logger
		))->forCaseType(
			objectService: $objectService,
			register: $register,
			caseTypeId: (string)($caseType['id'] ?? self::CASE_TYPE_ID),
			wooRequest: $wooRequest
		);
		if ($properties !== []) {
			$case['properties'] = $properties;
		}

		// WHO ASKED, BY NAME. The case's Requester reads `initiatorDisplayName`
		// when no register row was picked; a resident on the portal is not one.
		$requester = trim((string)($wooRequest['verzoekerNaam'] ?? ''));
		if ($requester !== '' && trim((string)($case['initiatorDisplayName'] ?? '')) === '') {
			$case['initiatorDisplayName'] = $requester;
		}

		$initial = trim((string)($caseType['initialStatus'] ?? ''));
		if ($initial !== '') {
			$case['status'] = $initial;
		}

		$written = $this->cases->write(objectService: $objectService, register: $register, case: $case, caseType: $caseType);
		if ($written === null) {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The case could not be written.');
		}

		if ($written['id'] === '') {
			throw new WooRequestRefused(WooRequestRefused::UNAVAILABLE, 'The case was written without an id.');
		}

		return $written;
	}//end writeCase()

}//end class
