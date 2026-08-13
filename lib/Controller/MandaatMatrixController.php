<?php

/**
 * Procest MandaatMatrixController.
 *
 * REST surface for the mandaat-matrix backend: authorization checks,
 * import, escalation approve/reject/list, audit-trail retrieval.
 * Defers all business logic to the services in lib/Service/Mandaat*
 * (ADR-022). Per-object IDOR guards live in the services themselves
 * (e.g. {@see MandaatEscalatieService::approveEscalatie} re-checks
 * that the caller is the resolved mandate holder).
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/mandaat-matrix-09-tests-and-docs/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\MandaatCheckService;
use OCA\Procest\Service\MandaatEscalatieService;
use OCA\Procest\Service\MandaatGebruikService;
use OCA\Procest\Service\MandaatImportService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for the mandaat-matrix backend.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class MandaatMatrixController extends Controller {

	use SearchesObjects;

	/**
	 * Case-property keys that carry identity. They are stripped from
	 * client-supplied input and repopulated server-side — a caller must never be
	 * able to supply (or withhold) the identity its own authorization is
	 * decided on.
	 *
	 * @var array<int, string>
	 */
	private const CLIENT_SUPPLIED_IDENTITY_KEYS = [
		'userBsn',
		'applicantBsn',
		'userBsnHash',
		'applicantBsnHash',
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param IUserSession $userSession User session (for current user id).
	 * @param MandaatCheckService $check Check service.
	 * @param MandaatEscalatieService $escalation Escalation service.
	 * @param MandaatGebruikService $gebruik Audit log service.
	 * @param MandaatImportService $import Import service.
	 * @param SettingsService $settings Settings (OpenRegister access).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly MandaatCheckService $check,
		private readonly MandaatEscalatieService $escalation,
		private readonly MandaatGebruikService $gebruik,
		private readonly MandaatImportService $import,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Authorization probe — UI can call this before submitting a decision.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
	 */
	public function probe(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$decisionType = (string)($body['decisionType'] ?? '');
		$caseId = (string)($body['caseId'] ?? '');
		$caseProps = (array)($body['caseProperties'] ?? []);
		if ($decisionType === '' || $caseId === '') {
			return $this->badRequest(msg: 'decisionType and caseId are required');
		}

		// $caseProps arrives from the REQUEST BODY. Identity read from the
		// requester is not identity: previously the belangenconflict check gated
		// on `caseProperties.userBsn`, so a caller could force "no conflict" just
		// by omitting it. Strip every identity key the client may have sent and
		// re-derive the applicant identity server-side from the case object.
		$caseProps = $this->stripClientSuppliedIdentity(caseProperties: $caseProps);
		$caseProps = array_merge($caseProps, $this->resolveApplicantIdentity(caseId: $caseId));

		$userId = $this->currentUserId();
		$r = $this->check->isAuthorized($userId, $decisionType, $caseId, $caseProps);
		return new JSONResponse($r);
	}//end probe()

	/**
	 * Remove client-supplied identity keys from case properties.
	 *
	 * @param array<string, mixed> $caseProperties Client-supplied case properties.
	 *
	 * @return array<string, mixed> The properties without any identity keys.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function stripClientSuppliedIdentity(array $caseProperties): array {
		foreach (self::CLIENT_SUPPLIED_IDENTITY_KEYS as $key) {
			unset($caseProperties[$key]);
		}

		return $caseProperties;
	}//end stripClientSuppliedIdentity()

	/**
	 * Resolve the applicant's identity server-side from the case object.
	 *
	 * The case's `initiatorSourceId` holds the initiator's identifying number in
	 * its source system; it is a BSN only when `initiatorType` is `person` (for
	 * `company` it is a KvK number and for `contact` a contact URI — neither is
	 * a natural person, so neither can produce a belangenconflict).
	 *
	 * Returns an empty array when the case or the initiator cannot be resolved.
	 * That is not a fail-open: `ConflictOfInterestService` treats an absent
	 * applicant identity as "nobody to conflict with", while an unresolvable
	 * CASE WORKER identity still blocks.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, string> `['applicantBsn' => ...]` or `[]`.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function resolveApplicantIdentity(string $caseId): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settings->getConfigValue('register');
		$caseSchema = $this->settings->getConfigValue('case_schema');
		if (empty($register) === true || empty($caseSchema) === true) {
			return [];
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest MandaatMatrixController: could not resolve applicant identity: ' . $e->getMessage()
			);
			return [];
		}

		if ($case === null) {
			return [];
		}

		if ((string)($case['initiatorType'] ?? '') !== 'person') {
			return [];
		}

		$applicantBsn = (string)($case['initiatorSourceId'] ?? '');
		if ($applicantBsn === '') {
			return [];
		}

		return ['applicantBsn' => $applicantBsn];
	}//end resolveApplicantIdentity()

	/**
	 * Import a CSV of mandaten under a new MandateringsBesluit.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function importPreview(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();

		// The request body keys are a PUBLISHED CONTRACT, not internal names:
		// an existing caller posts besluitNummer/besluitNaam today. The new
		// English keys are preferred, the Dutch ones still accepted, so this
		// rename cannot break a client that has not been updated yet. Drop the
		// fallback only once no caller sends the old keys.
		$decisionNumber = (string)($body['decisionNumber'] ?? $body['besluitNummer'] ?? '');
		$decisionName = (string)($body['decisionName'] ?? $body['besluitNaam'] ?? '');
		$decideskUuid = (string)($body['decideskUuid'] ?? '');
		$csv = (string)($body['csv'] ?? '');
		if ($decisionNumber === '' || $decisionName === '' || $csv === '') {
			return $this->badRequest(msg: 'decisionNumber, decisionName and csv are required');
		}

		try {
			$r = $this->import->importFromCsv($decisionNumber, $decisionName, $decideskUuid, $csv);
			return new JSONResponse($r, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end importPreview()

	/**
	 * Approve a previously-imported (concept) besluit.
	 *
	 * @param string $importId Besluit id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function importApprove(string $importId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$r = $this->import->approveImport($importId);
			return new JSONResponse($r);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end importApprove()

	/**
	 * Open a new escalation for a mandate-denied decision.
	 *
	 * The auth posture mirrors escalateReject() deliberately: authenticated
	 * caseworker, per-object authorization delegated to the service (ADR-022).
	 *
	 * `initiatorId` is NEVER read from the request body. It is the identity the
	 * escalation is recorded against, and identity supplied by the requester is
	 * not identity — it is derived from the session, the same stance probe()
	 * takes when it strips CLIENT_SUPPLIED_IDENTITY_KEYS.
	 *
	 * The body is read via IRequest::getParam() rather than the file-local
	 * jsonBody() helper: Nextcloud merges a JSON request body into the request
	 * parameters, and unlike a raw php://input read that seam is observable
	 * from a test, so this endpoint can actually be covered.
	 *
	 * @return JSONResponse The created escalation, 201.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function escalateCreate(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$caseId = (string)$this->request->getParam('zaakId', '');
		$decisionType = (string)$this->request->getParam('decisionType', '');
		$escalationReason = (string)$this->request->getParam('escalatieReden', '');
		if ($caseId === '' || $decisionType === '' || $escalationReason === '') {
			return $this->badRequest(msg: 'zaakId, decisionType and escalatieReden are required');
		}

		try {
			$r = $this->escalation->createEscalatie(
				$caseId,
				$decisionType,
				$this->currentUserId(),
				$escalationReason
			);
			return new JSONResponse($r, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end escalateCreate()

	/**
	 * Approve an open escalation.
	 *
	 * @param string $id Escalation id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function escalateApprove(string $id): JSONResponse {
		$userId = $this->currentUserId();
		try {
			$r = $this->escalation->approveEscalatie($id, $userId);
			return new JSONResponse($r);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}//end escalateApprove()

	/**
	 * Reject an open escalation.
	 *
	 * @param string $id Escalation id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function escalateReject(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$reason = (string)($body['reason'] ?? '');
		if ($reason === '') {
			return $this->badRequest(msg: 'reason is required');
		}

		try {
			$r = $this->escalation->rejectEscalatie($id, $reason);
			return new JSONResponse($r);
		} catch (Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end escalateReject()

	/**
	 * Get the decision audit trail for a case.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function auditTrail(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		return new JSONResponse($this->gebruik->getDecisionAuditTrail($caseId));
	}//end auditTrail()

	/**
	 * Applicable mandates for the case, filtered to the current user's roles.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
	 */
	public function applicable(string $caseId): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$userId = $this->currentUserId();
		$caseType = (string)$this->request->getParam('caseType', '');
		$decisionType = (string)$this->request->getParam('decisionType', '');
		try {
			$rows = $this->check->getApplicableForUser($userId, $caseType, $decisionType);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MandaatMatrixController.applicable failed',
				['caseId' => $caseId, 'error' => $e->getMessage()],
			);
			$rows = [];
		}

		return new JSONResponse($rows);
	}//end applicable()

	/**
	 * Resolve the current user id, or empty string when unauthenticated.
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return (string)$user->getUID();
		}

		return '';
	}//end currentUserId()

	/**
	 * Read and decode the JSON request body into an array.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array {
		// OCP\IRequest::getContent() is protected on the concrete OC
		// request; read raw payload from php://input instead.
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()

	/**
	 * Build a 400 Bad Request JSON response.
	 *
	 * @param string $msg Message.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $msg): JSONResponse {
		return new JSONResponse(['message' => $msg], Http::STATUS_BAD_REQUEST);
	}//end badRequest()
}//end class
