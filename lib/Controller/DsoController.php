<?php

/**
 * DSO Controller
 *
 * Thin HTTP controller for the DSO Omgevingsloket integration. Delegates all
 * business logic to the appropriate services. Exposes a dashboard view and
 * workflow actions (status transition, beschikking generation, samenwerking
 * initiation/response, doorsturen) for omgevingsvergunning zaken.
 *
 * The dashboard read and the guarded case writes (the per-object guard, then
 * the status transition, beschikking or doorsturen event) are delegated to
 * {@see DsoCaseActions}, the samenwerkverzoek writes to
 * {@see DsoSamenwerkingActions} (ADR-022), so this controller only validates
 * input and maps outcomes onto responses.
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

use OCA\Dossiq\Controller\Support\DsoCaseActions;
use OCA\Dossiq\Controller\Support\DsoSamenwerkingActions;
use OCA\Dossiq\Controller\Support\RequestBodyFields;
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
	 * Reads the request body's fields and refuses a missing or unknown choice.
	 *
	 * @var RequestBodyFields
	 */
	private readonly RequestBodyFields $fields;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param DsoCaseActions $cases The dashboard read and the guarded writes on a case
	 * @param DsoSamenwerkingActions $samenwerking The samenwerkverzoek writes
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DsoCaseActions $cases,
		private readonly DsoSamenwerkingActions $samenwerking,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->fields = new RequestBodyFields();
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
			$outcome = $this->cases->dashboard(
				params: $params,
				filters: [
					'activiteitgroep' => (string)$activiteitgroep,
					'regelkwalificatie' => (string)$regelkwalificatie,
					'location' => (string)$location,
				]
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

		$body = $this->fields->read(request: $this->request);
		$newStatus = (string)($body['newStatus'] ?? '');

		$refusal = $this->fields->choiceRefusal(
			value: $newStatus,
			allowed: self::DSO_STATUSES,
			messages: ['required' => 'newStatus is required', 'invalid' => 'Invalid status value']
		);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->applyStatusTransition(user: $user, request: ['caseId' => $caseId, 'body' => $body]);
		} catch (\Exception $e) {
			return $this->failure(exception: $e, endpoint: ['action' => 'transitionStatus', 'message' => 'Could not transition status']);
		}//end try
	}//end transitionStatus()

	/**
	 * Move a case the user may change to a new DSO status.
	 *
	 * @param IUser $user The current user.
	 * @param array{caseId: string, body: array} $request The case, and the request body with the requested status.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Exception When reading or transitioning the case fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function applyStatusTransition(IUser $user, array $request): JSONResponse {
		['caseId' => $caseId, 'body' => $body] = $request;
		$case = $this->cases->mutableCase(caseId: $caseId, user: $user);
		if ($case === null) {
			return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->cases->transition(caseId: $caseId, body: $body, userId: $user->getUID()));
	}//end applyStatusTransition()

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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[NoAdminRequired]
	public function generateBeschikking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->fields->read(request: $this->request);
		$refusal = $this->fields->choiceRefusal(
			value: (string)($body['outcome'] ?? ''),
			allowed: ['granted', 'refused'],
			messages: ['required' => 'outcome is required', 'invalid' => 'Invalid outcome value']
		);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$case = $this->cases->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->cases->beschikking(caseId: $caseId, body: $body), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				endpoint: ['action' => 'generateBeschikking', 'message' => 'Could not generate beschikking']
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[NoAdminRequired]
	public function initiateSamenwerking(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->fields->read(request: $this->request);
		if ((string)($body['requestedCompetentAuthority'] ?? '') === '') {
			return $this->badRequest(message: 'aangezochtBevoegdGezag is required');
		}

		try {
			$case = $this->cases->mutableCase(caseId: $caseId, user: $user);
			if ($case === null) {
				return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->samenwerking->startSamenwerking(caseId: $caseId, body: $body), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				endpoint: ['action' => 'initiateSamenwerking', 'message' => 'Could not initiate samenwerking']
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	#[NoAdminRequired]
	public function respondSamenwerking(string $samenwerkId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->fields->read(request: $this->request);

		try {
			if ($this->samenwerking->mutableSamenwerk(samenwerkId: $samenwerkId, user: $user) === null) {
				return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->samenwerking->answerSamenwerking(samenwerkId: $samenwerkId, body: $body));
		} catch (\Exception $e) {
			return $this->failure(
				exception: $e,
				endpoint: ['action' => 'respondSamenwerking', 'message' => 'Could not respond to samenwerking']
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

		$body = $this->fields->read(request: $this->request);
		$targetBevoegdGezag = (string)($body['targetBevoegdGezag'] ?? '');

		if ($targetBevoegdGezag === '') {
			return $this->badRequest(message: 'targetBevoegdGezag is required');
		}

		try {
			return $this->forwardCase(user: $user, request: ['caseId' => $caseId, 'body' => $body]);
		} catch (\Exception $e) {
			return $this->failure(exception: $e, endpoint: ['action' => 'doorsturen', 'message' => 'Could not doorsturen']);
		}//end try
	}//end doorsturen()

	/**
	 * Forward a case the user may change to another bevoegd gezag.
	 *
	 * @param IUser $user The current user.
	 * @param array{caseId: string, body: array} $request The case, and the request body: the bevoegd gezag to
	 *                                                    forward to and why.
	 *
	 * @return JSONResponse
	 *
	 * @throws \Exception When reading the case or dispatching fails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function forwardCase(IUser $user, array $request): JSONResponse {
		['caseId' => $caseId, 'body' => $body] = $request;
		$targetBevoegdGezag = (string)($body['targetBevoegdGezag'] ?? '');
		$case = $this->cases->mutableCase(caseId: $caseId, user: $user);
		if ($case === null) {
			return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
		}

		$this->cases->forward(case: $case, caseId: $caseId, body: $body, userId: $user->getUID());

		return $this->forwarded(caseId: $caseId, targetBevoegdGezag: $targetBevoegdGezag);
	}//end forwardCase()

	/**
	 * Map a workflow exception onto a response.
	 *
	 * An authorization refusal surfaces as a 403; anything else is logged and
	 * reported as a 500 with the endpoint's own message.
	 *
	 * @param \Exception $exception The caught exception.
	 * @param array{action: string, message: string} $endpoint The endpoint name, for the log line, and the 500
	 *                                                        message for this endpoint.
	 *
	 * @return JSONResponse The mapped error response.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function failure(\Exception $exception, array $endpoint): JSONResponse {
		['action' => $action, 'message' => $message] = $endpoint;
		if ($exception->getMessage() === 'Not authorized') {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->logger->error('Dossiq DsoController::' . $action . ' failed: ' . $exception->getMessage());
		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()

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
}//end class
