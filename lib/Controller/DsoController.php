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
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\Dso\DsoDoorsturenNotifier;
use OCA\Procest\Service\Dso\DsoObjectRepository;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing DSO Omgevingsloket endpoints.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */
class DsoController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param DsoCaseService $dsoCaseService The DSO case service
	 * @param BeschikkingGenerationService $beschikkingService The beschikking generation service
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
		private readonly BeschikkingGenerationService $beschikkingService,
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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function dashboard(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$activiteitgroep = $this->request->getParam('activiteitgroep', '');
		$regelkwalificatie = $this->request->getParam('regelkwalificatie', '');
		$locatie = $this->request->getParam('locatie', '');

		$params = ['caseType' => 'omgevingsvergunning'];

		foreach (['status', 'procedureType', 'gemeenteCode'] as $key) {
			$value = (string)$this->request->getParam($key, '');
			if ($value !== '') {
				$params[$key] = $value;
			}
		}

		$params['_limit'] = 100;
		$params['_offset'] = 0;

		try {
			$outcome = $this->repository->fetchDashboard(
				params: $params,
				activiteitgroep: (string)$activiteitgroep,
				regelkwalificatie: (string)$regelkwalificatie,
				locatie: (string)$locatie
			);

			if ($outcome['error'] !== null) {
				return new JSONResponse(['error' => $outcome['error']], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			return new JSONResponse(['results' => $outcome['results'], 'count' => count($outcome['results'])]);
		} catch (\Throwable $e) {
			$this->logger->error('Procest DsoController::dashboard failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Could not load dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end dashboard()

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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
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

		$allowedStatuses = ['ingediend', 'in_behandeling', 'verleend', 'geweigerd', 'ingetrokken'];
		if (in_array(needle: $newStatus, haystack: $allowedStatuses, strict: true) === false) {
			return new JSONResponse(['error' => 'Invalid status value'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$zaak = $this->repository->findZaak(caseId: $caseId);
			if ($zaak === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

			$updated = $this->dsoCaseService->transitionStatus(
				zaakId: $caseId,
				newStatus: $newStatus,
				besluitdatum: $this->optionalString(body: $body, key: 'besluitdatum'),
				toelichting: $this->optionalString(body: $body, key: 'toelichting'),
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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function generateBeschikking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$outcome = (string)($body['outcome'] ?? '');
		$motivation = (string)($body['motivation'] ?? '');

		if ($outcome === '') {
			return new JSONResponse(['error' => 'outcome is required'], Http::STATUS_BAD_REQUEST);
		}

		$allowedOutcomes = ['verleend', 'geweigerd'];
		if (in_array(needle: $outcome, haystack: $allowedOutcomes, strict: true) === false) {
			return new JSONResponse(['error' => 'Invalid outcome value'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$zaak = $this->repository->findZaak(caseId: $caseId);
			if ($zaak === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

			$result = $this->beschikkingService->generateBeschikking(
				zaakId: $caseId,
				outcome: $outcome,
				motivation: $motivation
			);

			return new JSONResponse($result, Http::STATUS_CREATED);
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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function initiateSamenwerking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$bevoegdGezag = (string)($body['aangezochtBevoegdGezag'] ?? '');
		$rationale = (string)($body['rationale'] ?? '');

		if ($bevoegdGezag === '') {
			return new JSONResponse(
				['error' => 'aangezochtBevoegdGezag is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$zaak = $this->repository->findZaak(caseId: $caseId);
			if ($zaak === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

			$samenwerkverzoek = $this->samenwerkService->initiateSamenwerking(
				zaakId: $caseId,
				aangezochtGezag: $bevoegdGezag,
				rationale: $rationale
			);

			return new JSONResponse($samenwerkverzoek, Http::STATUS_CREATED);
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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function respondSamenwerking(string $samenwerkId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$accept = (bool)($body['accept'] ?? false);
		$advies = (string)($body['advies'] ?? '');

		try {
			$samenwerkverzoek = $this->repository->findSamenwerkverzoek(samenwerkId: $samenwerkId);
			if ($samenwerkverzoek === null) {
				return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
			}

			$this->samenwerkService->authorizeSamenwerkMutation(
				samenwerk: $samenwerkverzoek,
				user: $user
			);

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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	#[NoAdminRequired]
	public function doorsturen(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$targetBevoegdGezag = (string)($body['targetBevoegdGezag'] ?? '');
		$reden = (string)($body['reden'] ?? '');

		if ($targetBevoegdGezag === '') {
			return new JSONResponse(
				['error' => 'targetBevoegdGezag is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$zaak = $this->repository->findZaak(caseId: $caseId);
			if ($zaak === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			$this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

			$this->doorsturenNotifier->dispatchDoorgestuurd(
				zaak: $zaak,
				caseId: $caseId,
				targetBevoegdGezag: $targetBevoegdGezag,
				reden: $reden,
				userId: $user->getUID()
			);

			return new JSONResponse(
				[
					'status' => 'doorgestuurd',
					'caseId' => $caseId,
					'targetBevoegdGezag' => $targetBevoegdGezag,
				]
			);
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
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	private function failure(\Exception $exception, string $action, string $message): JSONResponse {
		if ($exception->getMessage() === 'Not authorized') {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->logger->error('Procest DsoController::' . $action . ' failed: ' . $exception->getMessage());
		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()

	/**
	 * Read an optional string field from the decoded body.
	 *
	 * @param array<string,mixed> $body The decoded request body.
	 * @param string $key The field name.
	 *
	 * @return string|null The value, or null when absent.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
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
		// Prefer the request object's getContent() when it's reachable —
		// test stubs expose a public getContent() and we fall through to
		// php://input only when the concrete OC request hides it.
		$content = '';
		if (method_exists($this->request, 'getContent') === true) {
			try {
				$raw = $this->request->getContent();
				if (is_string($raw) === true) {
					$content = $raw;
				}
			} catch (\Throwable $e) {
				$content = '';
			}
		}

		if ($content === '') {
			$content = (string)file_get_contents('php://input');
		}

		if ($content === '') {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end readJsonBody()
}//end class
