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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\BurgerIdentificationService;
use OCA\Dossiq\Service\CaseVoorbladService;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\DoorverbindingService;
use OCA\Dossiq\Service\Kcc\GuardedCitizenLookup;
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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
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
	 * @param GuardedCitizenLookup $lookups Who may look a citizen up, and the audit
	 *        row that says they did. One collaborator rather than two, because the
	 *        refusal is what catches enumeration and a caller that remembers the
	 *        guard and forgets the recorder leaves that pattern unrecorded.
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
		private readonly GuardedCitizenLookup $lookups,
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
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function refuseLookup(string $uid, string $burgerId): JSONResponse {
		$this->lookups->recordRefusal(uid: $uid, burgerId: $burgerId);

		return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
	}//end refuseLookup()

	/**
	 * The signed-in KCC employee who may look up citizens, or the refusal.
	 *
	 * A caller without a session is a 401; a caller without the KCC role is
	 * a 403, and the refused lookup is recorded with the citizen reference.
	 *
	 * @param string $burgerId The citizen reference the request looks with.
	 *
	 * @return \OCP\IUser|JSONResponse The caller, or the refusal.
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function kccCaller(string $burgerId): \OCP\IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->lookups->isAllowed(user: $user) === false) {
			return $this->refuseLookup(uid: $user->getUID(), burgerId: $burgerId);
		}

		return $user;
	}//end kccCaller()

	/**
	 * Record a lookup that was answered.
	 *
	 * @param \OCP\IUser $user     The caller.
	 * @param string     $burgerId The citizen reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function recordLookup(\OCP\IUser $user, string $burgerId): void {
		$this->lookups->recordAnswer(user: $user, burgerId: $burgerId);
	}//end recordLookup()

	/**
	 * Create a contactmoment and return the case-voorblad for the burger.
	 *
	 * @return JSONResponse The created contactmoment plus case-voorblad.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function create(): JSONResponse {
		// This method both writes a contactmoment against a caller-supplied
		// citizen identifier and returns that citizen's voorblad, so it is the
		// same exposure as `voorblad()` with a write attached.
		$user = $this->kccCaller(burgerId: (string)$this->request->getParam('geidentificeerdeBurgerId', ''));
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$data = $this->contactMomentFields(user: $user);
		[$burgerId, $method] = $this->identifiedCitizen(callerIdentification: $data['callerIdentification']);
		$data['geidentificeerdeBurgerId'] = $this->citizenOrNull(burgerId: $burgerId);
		$data['identificationMethod'] = $method;

		try {
			$contactmoment = $this->contactMomentService->createContactMoment($data);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		// The contact moment this caller just WROTE is not redacted: they typed
		// its summary and its caller identification a moment ago, and handing
		// back a blanked copy of what they just submitted would read as the
		// write having failed.
		return new JSONResponse(
			['contactmoment' => $contactmoment, 'voorblad' => $this->voorbladFor(user: $user, burgerId: $burgerId)]
		);
	}//end create()

	/**
	 * The contact moment's fields as the request sends them, by the signed-in employee.
	 *
	 * @param \OCP\IUser $user The KCC employee.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	private function contactMomentFields(\OCP\IUser $user): array {
		return [
			'notificationChannel' => (string)$this->request->getParam('notificationChannel', ''),
			'direction' => (string)$this->request->getParam('direction', 'inbound'),
			'callerIdentification' => (string)$this->request->getParam('callerIdentification', ''),
			'nature' => (string)$this->request->getParam('nature', 'informatieverzoek'),
			'summary' => (string)$this->request->getParam('summary', ''),
			'kccEmployeeId' => $user->getUID(),
			'transcript' => (string)$this->request->getParam('transcript', ''),
		];
	}//end contactMomentFields()

	/**
	 * The citizen and how they were identified.
	 *
	 * Auto-resolve a burger from the caller identifier when none supplied.
	 *
	 * @param string $callerIdentification The caller identifier.
	 *
	 * @return array{0: string, 1: string} The citizen ('' when unknown) and the identification method.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	private function identifiedCitizen(string $callerIdentification): array {
		$burgerId = (string)$this->request->getParam('geidentificeerdeBurgerId', '');
		$method = (string)$this->request->getParam('identificationMethod', 'non_geidentificeerd');
		if ($burgerId === '' && $callerIdentification !== '') {
			$resolved = $this->burgerService->lookupByIdentifier($callerIdentification);
			if ($resolved !== '') {
				$burgerId = $resolved;
				$method = 'identificatievragen';
			}
		}

		return [$burgerId, $method];
	}//end identifiedCitizen()

	/**
	 * The citizen as stored on the contact moment: null when unknown.
	 *
	 * @param string $burgerId The citizen, or ''.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 */
	private function citizenOrNull(string $burgerId): ?string {
		if ($burgerId === '') {
			return null;
		}

		return $burgerId;
	}//end citizenOrNull()

	/**
	 * The citizen's voorblad, redacted for the caller, with the lookup recorded; null without a citizen.
	 *
	 * @param \OCP\IUser $user     The caller.
	 * @param string     $burgerId The citizen, or ''.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	private function voorbladFor(\OCP\IUser $user, string $burgerId): ?array {
		if ($burgerId === '') {
			return null;
		}

		$voorblad = $this->lookups->redactForCaller(
			user: $user,
			payload: $this->caseVoorbladService->getCaseVoorblad($burgerId)
		);
		$this->recordLookup(user: $user, burgerId: $burgerId);

		return $voorblad;
	}//end voorbladFor()

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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function index(string $burgerId = '', int $limit = 50): JSONResponse {
		// `$burgerId` is a citizen identifier taken straight off the query
		// string; without this the whole contact history of any citizen was
		// readable by every authenticated account (PROC-IDOR-01).
		$user = $this->kccCaller(burgerId: $burgerId);
		if ($user instanceof JSONResponse) {
			return $user;
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
			$this->lookups->redactForCaller(
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function voorblad(string $burgerId = ''): JSONResponse {
		// The voorblad resolves a raw citizen identifier into that citizen's
		// open cases and recent contact history. Reproduced live at HTTP 200
		// for an unrelated authenticated account before this guard existed
		// (PROC-IDOR-01) — iterating BSN-shaped ids walked the population.
		$user = $this->kccCaller(burgerId: $burgerId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		if ($burgerId === '') {
			return new JSONResponse(['error' => 'burgerId is required'], Http::STATUS_BAD_REQUEST);
		}

		$voorblad = $this->caseVoorbladService->getCaseVoorblad($burgerId);
		$this->recordLookup(user: $user, burgerId: $burgerId);

		return new JSONResponse(
			$this->lookups->redactForCaller(user: $user, payload: $voorblad)
		);
	}//end voorblad()

	/**
	 * Execute the "Status terugkoppelen" quick-action.
	 *
	 * @return JSONResponse The draft text, or the recorded activity when confirmed.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function nieuweZaak(): JSONResponse {
		// Creates a municipal case bound to a caller-supplied citizen id.
		$user = $this->kccCaller(burgerId: (string)$this->request->getParam('burgerId', ''));
		if ($user instanceof JSONResponse) {
			return $user;
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
	 * @spec openspec/specs/security-hardening/spec.md#requirement-a-citizen-lookup-is-rate-limited-per-account-req-sec-cl-2
	 */
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function klachtRegistreren(): JSONResponse {
		// Takes an arbitrary `caseId` AND an arbitrary `burgerId`.
		$user = $this->kccCaller(burgerId: (string)$this->request->getParam('burgerId', ''));
		if ($user instanceof JSONResponse) {
			return $user;
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
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
