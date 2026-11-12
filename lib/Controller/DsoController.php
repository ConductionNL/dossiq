<?php

/**
 * DSO Controller
 *
 * Thin HTTP controller for the DSO Omgevingsloket integration. Delegates all
 * business logic to the appropriate services. Exposes a dashboard view and
 * workflow actions (status transition, beschikking generation, samenwerking
 * initiation/response, doorsturen) for omgevingsvergunning zaken.
 *
 * OpenRegister reads are delegated to {@see DsoObjectRepository} and the
 * doorsturen domain event to {@see DsoDoorsturenNotifier} (ADR-022), so this
 * controller only validates input, applies the authorization guard and maps
 * outcomes onto responses.
 *
 * All endpoints require authentication (@NoAdminRequired) and carry per-object
 * authorization guards (ADR-005 Rule 3) to prevent IDOR.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\JsonRequestBody;
use OCA\Dossiq\Service\BeschikkingGenerationService;
use OCA\Dossiq\Service\Dso\DsoDoorsturenNotifier;
use OCA\Dossiq\Service\Dso\DsoObjectRepository;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\SamenwerkverzoekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing DSO Omgevingsloket endpoints.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */
class DsoController extends Controller {

	/**
	 * The DSO-LV statuses the case schema declares for `dsoStatus`.
	 *
	 * @var list<string>
	 */
	private const DSO_STATUSES = ['submitted', 'in_handling', 'granted', 'refused', 'withdrawn'];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param DsoCaseService $dsoCaseService The DSO case service
	 * @param BeschikkingGenerationService $decisionService The beschikking generation service
	 * @param SamenwerkverzoekService $samenwerkService The samenwerkverzoek service
	 * @param DsoObjectRepository $repository The OpenRegister read collaborator
	 * @param DsoDoorsturenNotifier $doorsturenNotifier The doorsturen event dispatcher
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DsoCaseService $dsoCaseService,
		private readonly BeschikkingGenerationService $decisionService,
		private readonly SamenwerkverzoekService $samenwerkService,
		private readonly DsoObjectRepository $repository,
		private readonly DsoDoorsturenNotifier $doorsturenNotifier,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return a filtered list of DSO omgevingsvergunning cases for the dashboard.
	 *
	 * Reads filter params from the query string and returns matching cases.
	 * No per-object auth required for listing: all authenticated users may
	 * view the dashboard overview.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function dashboard(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$activiteitgroep = $this->request->getParam('activiteitgroep', '');
		$regelkwalificatie = $this->request->getParam('regelkwalificatie', '');
		$location = $this->request->getParam('location', '');

		$params = $this->dashboardParams();

		try {
			$outcome = $this->repository->fetchDashboard(
				params: $params,
				activiteitgroep: (string)$activiteitgroep,
				regelkwalificatie: (string)$regelkwalificatie,
				location: (string)$location
			);

			return $this->dashboardAnswer(outcome: $outcome);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq DsoController::dashboard failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Could not load dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end dashboard()

	/**
	 * The dashboard query: the DSO cases, narrowed by the request's filters.
	 *
	 * DSO cases are the ones carrying a DSO-LV status. `caseType` and
	 * `status` hold uuids, so the old `caseType: omgevingsvergunning`
	 * filter matched no case at all. The `status` query parameter is the
	 * DSO value and narrows `dsoStatus`.
	 *
	 * @return array<string, mixed>
	 */
	private function dashboardParams(): array {
		$params = ['dsoStatus' => self::DSO_STATUSES];
		$status = (string)$this->request->getParam('status', '');
		if ($status !== '') {
			$params['dsoStatus'] = [$status];
		}

		foreach (['procedureType', 'municipalityCode'] as $key) {
			$value = (string)$this->request->getParam($key, '');
			if ($value !== '') {
				$params[$key] = $value;
			}
		}

		$params['_limit'] = 100;
		$params['_offset'] = 0;
		return $params;
	}//end dashboardParams()

	/**
	 * The dashboard's answer: the results, or a 503 when OpenRegister could not answer.
	 *
	 * @param array{error: string|null, results: array<int, mixed>} $outcome What the repository fetched.
	 *
	 * @return JSONResponse
	 */
	private function dashboardAnswer(array $outcome): JSONResponse {
		if ($outcome['error'] !== null) {
			return new JSONResponse(['error' => $outcome['error']], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['results' => $outcome['results'], 'count' => count($outcome['results'])]);
	}//end dashboardAnswer()

	/**
	 * Transition the status of a DSO case.
	 *
	 * Reads newStatus, besluitdatum, and toelichting from the request body.
	 * Authorizes the mutation (per-object IDOR guard) before delegating to
	 * DsoCaseService::transitionStatus().
	 *
	 * @param string $caseId The UUID of the case to transition
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function transitionStatus(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$newStatus = (string)($body['newStatus'] ?? '');

		if ($newStatus === '') {
			return new JSONResponse(['error' => 'newStatus is required'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array(needle: $newStatus, haystack: self::DSO_STATUSES, strict: true) === false) {
			return new JSONResponse(['error' => 'Invalid status value'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$case = $this->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$updated = $this->dsoCaseService->transitionStatus(
				caseId: $caseId,
				newStatus: $newStatus,
				besluitdatum: $this->optionalString(body: $body, key: 'besluitdatum'),
				notes: $this->optionalString(body: $body, key: 'notes'),
				userId: $user->getUID()
			);

			return new JSONResponse($updated);
		} catch (\Exception $e) {
			return $this->failure(exception: $e, action: 'transitionStatus', message: 'Could not transition status');
		}//end try
	}//end transitionStatus()

	/**
	 * Generate a beschikking document for a DSO case.
	 *
	 * Reads outcome and motivation from the request body. Authorizes the
	 * mutation before delegating to BeschikkingGenerationService.
	 *
	 * @param string $caseId The UUID of the case
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function generateBeschikking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$refusal = $this->outcomeRefusal(outcome: (string)($body['outcome'] ?? ''));
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$case = $this->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			return $this->writeBeschikking(caseId: $caseId, body: $body);
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				action: 'generateBeschikking',
				message: 'Could not generate beschikking'
			);
		}//end try
	}//end generateBeschikking()

	/**
	 * Initiate a samenwerking request for a DSO case.
	 *
	 * Reads aangezochtBevoegdGezag and rationale from the request body.
	 * Authorizes the mutation before delegating to SamenwerkverzoekService.
	 *
	 * @param string $caseId The UUID of the case
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function initiateSamenwerking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		if ((string)($body['requestedCompetentAuthority'] ?? '') === '') {
			return $this->badRequest(message: 'aangezochtBevoegdGezag is required');
		}

		try {
			$case = $this->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			return $this->writeSamenwerking(caseId: $caseId, body: $body);
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				action: 'initiateSamenwerking',
				message: 'Could not initiate samenwerking'
			);
		}//end try
	}//end initiateSamenwerking()

	/**
	 * Respond to an existing samenwerkverzoek.
	 *
	 * Reads accept and advies from the request body. Authorizes the mutation
	 * before delegating to SamenwerkverzoekService::respondToSamenwerking().
	 *
	 * @param string $samenwerkId The UUID of the samenwerkverzoek
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function respondSamenwerking(string $samenwerkId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$accept = (bool)($body['accept'] ?? false);
		$advies = (string)($body['advice'] ?? '');

		try {
			if ($this->mutableSamenwerk(samenwerkId: $samenwerkId, user: $user) === null) {
				return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
			}

			$updated = $this->samenwerkService->respondToSamenwerking(
				samenwerkId: $samenwerkId,
				accept: $accept,
				advies: $advies
			);

			return new JSONResponse($updated);
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				action: 'respondSamenwerking',
				message: 'Could not respond to samenwerking'
			);
		}//end try
	}//end respondSamenwerking()

	/**
	 * Doorsturen: forward a DSO case to another bevoegd gezag.
	 *
	 * Reads targetBevoegdGezag and reden from the request body, authorizes the
	 * mutation, and dispatches a VergunningDoorgestuurd generic event for
	 * downstream listeners.
	 *
	 * @param string $caseId The UUID of the case to forward
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function doorsturen(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$targetBevoegdGezag = (string)($body['targetBevoegdGezag'] ?? '');
		$reason = (string)($body['reason'] ?? '');

		if ($targetBevoegdGezag === '') {
			return $this->badRequest(message: 'targetBevoegdGezag is required');
		}

		try {
			$case = $this->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$this->doorsturenNotifier->dispatchDoorgestuurd(
				case: $case,
				caseId: $caseId,
				targetBevoegdGezag: $targetBevoegdGezag,
				reason: $reason,
				userId: $user->getUID()
			);

			return $this->forwarded(caseId: $caseId, targetBevoegdGezag: $targetBevoegdGezag);
		} catch (\Exception $e) {
			return $this->failure(exception: $e, action: 'doorsturen', message: 'Could not doorsturen');
		}//end try
	}//end doorsturen()

	/**
	 * Map a workflow exception onto a response.
	 *
	 * An authorization refusal surfaces as a 403; anything else is logged and
	 * reported as a 500 with the endpoint's own message.
	 *
	 * @param \Exception $exception The caught exception.
	 * @param string $action The endpoint name, for the log line.
	 * @param string $message The 500 message for this endpoint.
	 *
	 * @return JSONResponse The mapped error response.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function failure(\Exception $exception, string $action, string $message): JSONResponse {
		if ($exception->getMessage() === 'Not authorized') {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->logger->error('Dossiq DsoController::' . $action . ' failed: ' . $exception->getMessage());
		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()

	/**
	 * The case the user may change, or null when it does not exist.
	 *
	 * The per-object guard throws `Not authorized` when the user may not
	 * change it, which {@see failure()} answers as a 403.
	 *
	 * @param string $caseId The case.
	 * @param IUser  $user   The caller.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mutableCase(string $caseId, IUser $user): ?array {
		$case = $this->repository->findZaak(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		$this->dsoCaseService->authorizeZaakMutation(case: $case, user: $user);
		return $case;
	}//end mutableCase()

	/**
	 * The samenwerkverzoek the user may answer, or null when it does not exist.
	 *
	 * The guard throws `Not authorized` when the user may not answer it,
	 * which {@see failure()} answers as a 403.
	 *
	 * @param string $samenwerkId The samenwerkverzoek.
	 * @param IUser  $user        The caller.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mutableSamenwerk(string $samenwerkId, IUser $user): ?array {
		$samenwerkverzoek = $this->repository->findSamenwerkverzoek(samenwerkId: $samenwerkId);
		if ($samenwerkverzoek === null) {
			return null;
		}

		$this->samenwerkService->authorizeSamenwerkMutation(
			samenwerk: $samenwerkverzoek,
			user: $user
		);
		return $samenwerkverzoek;
	}//end mutableSamenwerk()

	/**
	 * A 400 naming what the request lacks.
	 *
	 * @param string $message The error.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * The confirmation of a forwarded case.
	 *
	 * @param string $caseId             The case.
	 * @param string $targetBevoegdGezag Where it went.
	 *
	 * @return JSONResponse
	 */
	private function forwarded(string $caseId, string $targetBevoegdGezag): JSONResponse {
		return new JSONResponse(
			[
				'status' => 'doorgestuurd',
				'caseId' => $caseId,
				'targetBevoegdGezag' => $targetBevoegdGezag,
			]
		);
	}//end forwarded()

	/**
	 * Refuse a missing or unknown beschikking outcome.
	 *
	 * @param string $outcome The requested outcome.
	 *
	 * @return JSONResponse|null The 400, or null for `granted` or `refused`.
	 */
	private function outcomeRefusal(string $outcome): ?JSONResponse {
		if ($outcome === '') {
			return new JSONResponse(['error' => 'outcome is required'], Http::STATUS_BAD_REQUEST);
		}

		$allowedOutcomes = ['granted', 'refused'];
		if (in_array(needle: $outcome, haystack: $allowedOutcomes, strict: true) === false) {
			return new JSONResponse(['error' => 'Invalid outcome value'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end outcomeRefusal()

	/**
	 * Generate the beschikking the body asks for.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $body   The request body.
	 *
	 * @return JSONResponse The 201 with the generated beschikking.
	 */
	private function writeBeschikking(string $caseId, array $body): JSONResponse {
		$result = $this->decisionService->generateBeschikking(
			caseId: $caseId,
			outcome: (string)($body['outcome'] ?? ''),
			motivation: (string)($body['motivation'] ?? '')
		);

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end writeBeschikking()

	/**
	 * Start the samenwerkverzoek the body asks for.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $body   The request body.
	 *
	 * @return JSONResponse The 201 with the samenwerkverzoek.
	 */
	private function writeSamenwerking(string $caseId, array $body): JSONResponse {
		$samenwerkverzoek = $this->samenwerkService->initiateSamenwerking(
			caseId: $caseId,
			aangezochtGezag: (string)($body['requestedCompetentAuthority'] ?? ''),
			rationale: (string)($body['rationale'] ?? '')
		);

		return new JSONResponse($samenwerkverzoek, Http::STATUS_CREATED);
	}//end writeSamenwerking()

	/**
	 * Read an optional string field from the decoded body.
	 *
	 * @param array<string,mixed> $body The decoded request body.
	 * @param string $key The field name.
	 *
	 * @return string|null The value, or null when absent.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function optionalString(array $body, string $key): ?string {
		if (isset($body[$key]) === false) {
			return null;
		}

		return (string)$body[$key];
	}//end optionalString()

	/**
	 * Decode a JSON request body safely.
	 *
	 * @return array<string,mixed>
	 */
	private function readJsonBody(): array {
		return (new JsonRequestBody())->read(request: $this->request);
	}//end readJsonBody()
}//end class
