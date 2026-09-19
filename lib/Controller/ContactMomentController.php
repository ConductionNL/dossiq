<?php

/**
 * Dossiq Contactmoment Controller.
 *
 * Authenticated KCC-medewerker API for logging contactmomenten, fetching the
 * case-voorblad, executing quick-actions, and answering doorverbindingen. Every
 * method requires an authenticated session (KCC-medewerker level); privileged
 * actions are additionally scoped server-side.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\BurgerIdentificationService;
use OCA\Dossiq\Service\CaseVoorbladService;
use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\DoorverbindingService;
use OCA\Dossiq\Service\Kcc\CitizenLookupRecorder;
use OCA\Dossiq\Service\QuickActionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * REST API for KCC contactmomenten and quick-actions.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
 */
class ContactMomentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ContactMomentService $contactMomentService The contactmoment service.
	 * @param CaseVoorbladService $caseVoorbladService The case-voorblad service.
	 * @param QuickActionService $quickActionService The quick-action service.
	 * @param DoorverbindingService $transferService The doorverbinding service.
	 * @param BurgerIdentificationService $burgerService The burger identification service.
	 * @param IUserSession $userSession The user session.
	 * @param CitizenLookupGuard $citizenLookupGuard The citizen-lookup role guard.
	 * @param CitizenLookupRecorder $lookupRecorder Writes one audit row per
	 *        lookup attempt, refusals included, because the refusal is what catches
	 *        enumeration.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContactMomentService $contactMomentService,
		private readonly CaseVoorbladService $caseVoorbladService,
		private readonly QuickActionService $quickActionService,
		private readonly DoorverbindingService $transferService,
		private readonly BurgerIdentificationService $burgerService,
		private readonly IUserSession $userSession,
		private readonly CitizenLookupGuard $citizenLookupGuard,
		private readonly CitizenLookupRecorder $lookupRecorder,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * How many citizen lookups one account may make in an hour.
	 *
	 * Above a call handler's real hour and far below a population walk. A KCC
	 * agent looks a citizen up once or twice per call, and the werkplek fetches
	 * the voorblad and the contact list as two calls, so a busy hour sits well
	 * inside sixty. The BRP holds about 18 million people; at sixty an hour a
	 * walk takes thirty-four years.
	 *
	 * Ten would be defensible on paper and would break the desk on a Monday
	 * morning, and a limit that breaks the desk is a limit somebody removes.
	 */
	public const LOOKUP_LIMIT_PER_HOUR = 60;

	/**
	 * Refuse the lookup, and record the refusal.
	 *
	 * The refusal is the half that catches the enumeration: an account refused
	 * four hundred times in an afternoon is not a handler who mistyped a BSN.
	 * Recording it here rather than at each call site is what keeps the two
	 * from drifting apart.
	 *
	 * @param string $uid       The account that was refused.
	 * @param string $burgerId  The citizen reference it looked with.
	 *
	 * @return JSONResponse The 403.
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function refuseLookup(string $uid, string $burgerId): JSONResponse {
		$this->lookupRecorder->record(
			employeeId: $uid,
			subjectId: $burgerId,
			allowed: false,
			fields: [],
			ground: 'geen kcc-rol',
		);

		return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
	}//end refuseLookup()

	/**
	 * Record a lookup that was answered.
	 *
	 * @param \OCP\IUser $user     The caller.
	 * @param string     $burgerId The citizen reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function recordLookup(\OCP\IUser $user, string $burgerId): void {
		$this->lookupRecorder->record(
			employeeId: $user->getUID(),
			subjectId: $burgerId,
			allowed: true,
			fields: $this->citizenLookupGuard->revealedFieldsFor(user: $user),
			ground: 'kcc-rol',
		);
	}//end recordLookup()

	/**
	 * Create a contactmoment and return the case-voorblad for the burger.
	 *
	 * @return JSONResponse The created contactmoment plus case-voorblad.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// This method both writes a contactmoment against a caller-supplied
		// citizen identifier and returns that citizen's voorblad, so it is the
		// same exposure as `voorblad()` with a write attached.
		if ($this->citizenLookupGuard->isCitizenLookupAllowed(user: $user) === false) {
			return $this->refuseLookup(
				uid: $user->getUID(),
				burgerId: (string)$this->request->getParam('geidentificeerdeBurgerId', '')
			);
		}

		$data = [
			'notificationChannel' => (string)$this->request->getParam('notificationChannel', ''),
			'direction' => (string)$this->request->getParam('direction', 'inbound'),
			'callerIdentification' => (string)$this->request->getParam('callerIdentification', ''),
			'nature' => (string)$this->request->getParam('nature', 'informatieverzoek'),
			'summary' => (string)$this->request->getParam('summary', ''),
			'kccEmployeeId' => $user->getUID(),
			'transcript' => (string)$this->request->getParam('transcript', ''),
		];

		// Auto-resolve a burger from the caller identifier when none supplied.
		$burgerId = (string)$this->request->getParam('geidentificeerdeBurgerId', '');
		$method = (string)$this->request->getParam('identificationMethod', 'non_geidentificeerd');
		if ($burgerId === '' && $data['callerIdentification'] !== '') {
			$resolved = $this->burgerService->lookupByIdentifier($data['callerIdentification']);
			if ($resolved !== '') {
				$burgerId = $resolved;
				$method = 'identificatievragen';
			}
		}

		$identifiedBurgerId = null;
		if ($burgerId !== '') {
			$identifiedBurgerId = $burgerId;
		}

		$data['geidentificeerdeBurgerId'] = $identifiedBurgerId;
		$data['identificationMethod'] = $method;

		try {
			$contactmoment = $this->contactMomentService->createContactMoment($data);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$voorblad = null;
		if ($burgerId !== '') {
			$voorblad = $this->citizenLookupGuard->redactForCaller(
				user: $user,
				payload: $this->caseVoorbladService->getCaseVoorblad($burgerId)
			);
			$this->recordLookup(user: $user, burgerId: $burgerId);
		}

		// The contact moment this caller just WROTE is not redacted: they typed
		// its summary and its caller identification a moment ago, and handing
		// back a blanked copy of what they just submitted would read as the
		// write having failed.
		return new JSONResponse(['contactmoment' => $contactmoment, 'voorblad' => $voorblad]);
	}//end create()

	/**
	 * List contactmomenten for an identified burger.
	 *
	 * @param string $burgerId The burger reference.
	 * @param int $limit The maximum number of records.
	 *
	 * @return JSONResponse The contactmoment list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function index(string $burgerId = '', int $limit = 50): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// `$burgerId` is a citizen identifier taken straight off the query
		// string; without this the whole contact history of any citizen was
		// readable by every authenticated account (PROC-IDOR-01).
		if ($this->citizenLookupGuard->isCitizenLookupAllowed(user: $user) === false) {
			return $this->refuseLookup(uid: $user->getUID(), burgerId: $burgerId);
		}

		if ($burgerId === '') {
			return new JSONResponse(['error' => 'burgerId is required'], Http::STATUS_BAD_REQUEST);
		}

		$records = $this->contactMomentService->listForBurger($burgerId, $limit);
		$this->recordLookup(user: $user, burgerId: $burgerId);

		// Redacted AFTER the read, because the read is what the record above
		// describes and because these rows were composed by this app rather
		// than rendered by OpenRegister, so no property rule has touched them.
		return new JSONResponse(
			$this->citizenLookupGuard->redactForCaller(
				user: $user,
				payload: ['contactmomenten' => $records]
			)
		);
	}//end index()

	/**
	 * Fetch the case-voorblad for a burger.
	 *
	 * @param string $burgerId The burger reference.
	 *
	 * @return JSONResponse The case-voorblad.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function voorblad(string $burgerId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The voorblad resolves a raw citizen identifier into that citizen's
		// open cases and recent contact history. Reproduced live at HTTP 200
		// for an unrelated authenticated account before this guard existed
		// (PROC-IDOR-01) — iterating BSN-shaped ids walked the population.
		if ($this->citizenLookupGuard->isCitizenLookupAllowed(user: $user) === false) {
			return $this->refuseLookup(uid: $user->getUID(), burgerId: $burgerId);
		}

		if ($burgerId === '') {
			return new JSONResponse(['error' => 'burgerId is required'], Http::STATUS_BAD_REQUEST);
		}

		$voorblad = $this->caseVoorbladService->getCaseVoorblad($burgerId);
		$this->recordLookup(user: $user, burgerId: $burgerId);

		return new JSONResponse(
			$this->citizenLookupGuard->redactForCaller(user: $user, payload: $voorblad)
		);
	}//end voorblad()

	/**
	 * Execute the "Status terugkoppelen" quick-action.
	 *
	 * @return JSONResponse The draft text, or the recorded activity when confirmed.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	public function statusGeven(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$confirm = (bool)$this->request->getParam('confirm', false);

		try {
			$result = $this->quickActionService->executeStatusTerugkoppelen($caseId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($confirm === true) {
			$this->contactMomentService->recordActivity(
				$caseId,
				'',
				'status_given',
				$user->getUID(),
				$result['draftText'],
			);
		}

		return new JSONResponse($result);
	}//end statusGeven()

	/**
	 * Execute the "Nieuwe zaak" quick-action.
	 *
	 * @return JSONResponse The new case id.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function nieuweZaak(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Creates a municipal case bound to a caller-supplied citizen id.
		if ($this->citizenLookupGuard->isCitizenLookupAllowed(user: $user) === false) {
			return $this->refuseLookup(
				uid: $user->getUID(),
				burgerId: (string)$this->request->getParam('burgerId', '')
			);
		}

		$caseType = (string)$this->request->getParam('caseType', '');
		$burgerId = (string)$this->request->getParam('burgerId', '');
		$details = (array)$this->request->getParam('details', []);

		try {
			$result = $this->quickActionService->executeNieuweZaak($caseType, $burgerId, $details);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end nieuweZaak()

	/**
	 * Execute the "Klacht registreren" quick-action.
	 *
	 * @return JSONResponse The klacht case id and deadline.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function klachtRegistreren(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Takes an arbitrary `caseId` AND an arbitrary `burgerId`.
		if ($this->citizenLookupGuard->isCitizenLookupAllowed(user: $user) === false) {
			return $this->refuseLookup(
				uid: $user->getUID(),
				burgerId: (string)$this->request->getParam('burgerId', '')
			);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$summary = (string)$this->request->getParam('summary', '');
		$burgerId = (string)$this->request->getParam('burgerId', '');

		try {
			$result = $this->quickActionService->executeKlachtRegistreren($caseId, $summary, $burgerId);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end klachtRegistreren()

	/**
	 * Execute the "Doorverbinden" quick-action (initiate warm transfer).
	 *
	 * @return JSONResponse The doorverbinding id and status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	public function doorverbinden(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$data = [
			'interactionId' => (string)$this->request->getParam('interactionId', ''),
			'fromEmployeeId' => $user->getUID(),
			'toEmployeeId' => $this->request->getParam('toEmployeeId', null),
			'toQueue' => $this->request->getParam('toQueue', null),
			'transferReason' => (string)$this->request->getParam('reason', ''),
			'contextSnapshot' => (string)$this->request->getParam('contextSnapshot', '{}'),
		];

		try {
			$result = $this->transferService->initiateWarmTransfer($data);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end doorverbinden()

	/**
	 * Accept a doorverbinding (by the receiving specialist).
	 *
	 * @param string $id The doorverbinding UUID.
	 *
	 * @return JSONResponse The updated doorverbinding.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	public function acceptDoorverbinding(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->transferService->acceptTransfer($id, $user->getUID()));
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end acceptDoorverbinding()

	/**
	 * Reject a doorverbinding with a reason.
	 *
	 * @param string $id The doorverbinding UUID.
	 *
	 * @return JSONResponse The updated doorverbinding.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	public function rejectDoorverbinding(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$reason = (string)$this->request->getParam('reason', '');

		try {
			return new JSONResponse($this->transferService->rejectTransfer($id, $reason, $user->getUID()));
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end rejectDoorverbinding()
}//end class
