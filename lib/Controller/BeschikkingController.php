<?php

/**
 * Dossiq Beschikking Controller.
 *
 * REST surface for the beschikking lifecycle:
 *
 *  - POST  /api/beschikkingen                     (compose, status -> ontwerp)
 *  - GET   /api/beschikkingen/{id}                (read)
 *  - PATCH /api/beschikkingen/{id}                (field edit; immutable once ondertekend)
 *  - PATCH /api/beschikkingen/{id}/akkoord        (mandaat approval)
 *  - PATCH /api/beschikkingen/{id}/onderteken     (TSP signing)
 *  - PATCH /api/beschikkingen/{id}/verzend        (Berichtenbox delivery)
 *  - GET   /api/beschikkingen/{id}/audit-pakket   (verifiable ZIP export)
 *
 * All endpoints require an authenticated user (#[NoAdminRequired]). Internal
 * exception messages are never returned to the client; static messages and
 * mapped HTTP statuses are used instead.
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for beschikking lifecycle endpoints.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) 60 against 50, crossed by
 * successor() (REQ-BES-012). Decision 182 forbids a new procedure-named
 * controller, so the endpoint joins the one this beschikking lifecycle already
 * has rather than a second beschikking class; the refactor programme of
 * decision 184 moves this controller onto generic capabilities as a whole.
 */
class BeschikkingController extends Controller {
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param BeschikkingService $decisionService The beschikking service.
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 * @param CaseAccessGuard $accessGuard Per-case mutation access, for issuing a successor.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BeschikkingService $decisionService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $accessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Compose a new beschikking from zaakdata. [T05]
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
	 */
	public function create(): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$caseId = (string)($body['caseId'] ?? '');
		$templateId = null;
		if (isset($body['templateId']) === true) {
			$templateId = (string)$body['templateId'];
		}

		$overrides = (array)($body['addressee'] ?? []);
		$payload = (array)$body;

		if ($caseId === '') {
			return new JSONResponse(['error' => 'zaakId is required'], Http::STATUS_BAD_REQUEST);
		}

		$merged = [];
		if ($overrides !== []) {
			$merged['addressee'] = $overrides;
		}

		foreach (['decisionType', 'rationale', 'decision'] as $field) {
			if (isset($payload[$field]) === true) {
				$merged[$field] = $payload[$field];
			}
		}

		try {
			$result = $this->decisionService->compose($caseId, $templateId, $merged);
			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (RefusedException $e) {
			// No number could be reserved (decision 167): a 503 the client may
			// retry, not the 500 the catch-all below would answer.
			return $this->refused(op: 'compose', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'compose', e: $e);
		}
	}//end create()

	/**
	 * Read a beschikking. [T06]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T06
	 */
	public function show(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$decision = $this->decisionService->find($id);
			if ($decision === null) {
				return new JSONResponse(['error' => 'Beschikking not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($decision);
		} catch (\Throwable $e) {
			return $this->fail(op: 'show', e: $e);
		}
	}//end show()

	/**
	 * Field-edit a beschikking (ontwerp only for content fields). [T11]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T11
	 */
	public function update(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$updates = $this->readJsonBody();
		// The number and both chain pointers are written by compose() and
		// issueSuccessor() only (REQ-BES-012), never by a field edit.
		unset($updates['id'], $updates['currentStatus'], $updates['reference'], $updates['supersedes'], $updates['supersededBy']);

		try {
			$result = $this->decisionService->updateFields($id, $updates);
			return new JSONResponse($result);
		} catch (RuntimeException $e) {
			return $this->mapRuntime(op: 'update', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'update', e: $e);
		}
	}//end update()

	/**
	 * Grant mandaat-approval. [T07]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T07
	 */
	public function akkoord(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$approvedBy = $uid;

		try {
			$result = $this->decisionService->akkoord($id, $approvedBy);
			return new JSONResponse($result);
		} catch (RefusedException $e) {
			return $this->refused(op: 'approved', e: $e);
		} catch (RuntimeException $e) {
			return $this->mapRuntime(op: 'approved', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'approved', e: $e);
		}
	}//end akkoord()

	/**
	 * Sign the beschikking via the TSP. [T08]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T08
	 */
	public function onderteken(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->readJsonBody();
		$tspProvider = (string)($body['tspProvider'] ?? '');
		if ($tspProvider === '') {
			return new JSONResponse(['error' => 'tspProvider is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->decisionService->onderteken($id, $tspProvider, $uid);
			return new JSONResponse($result);
		} catch (RefusedException $e) {
			// The coordinator seat, and anything else that refuses with a rule
			// rather than a sentinel. Caught BEFORE RuntimeException, which
			// RefusedException extends: the other order would answer every
			// refusal with mapRuntime()'s default 500.
			return $this->refused(op: 'onderteken', e: $e);
		} catch (RuntimeException $e) {
			return $this->mapRuntime(op: 'onderteken', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'onderteken', e: $e);
		}
	}//end onderteken()

	/**
	 * Deliver the beschikking via Berichtenbox. [T09]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T09
	 */
	public function verzend(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->decisionService->verzend($id, $uid);
			return new JSONResponse($result);
		} catch (RuntimeException $e) {
			return $this->mapRuntime(op: 'verzend', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'verzend', e: $e);
		}
	}//end verzend()

	/**
	 * Export the verifiable audit-pakket ZIP. [T10]
	 *
	 * @param string $id The beschikking UUID.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T10
	 */
	public function auditPakket(string $id): DataDownloadResponse|JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$zip = $this->decisionService->exportAuditPacket($id);
			$this->logger->info(
				'BeschikkingController: audit-pakket export',
				['decisionId' => $id, 'door' => $uid],
			);
			return new DataDownloadResponse(
				$zip,
				'audit-pakket-' . $id . '.zip',
				'application/zip',
			);
		} catch (RuntimeException $e) {
			return $this->mapRuntime(op: 'auditPakket', e: $e);
		} catch (\Throwable $e) {
			return $this->fail(op: 'auditPakket', e: $e);
		}
	}//end auditPakket()

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Issue a successor to the beschikking `{id}`.
	 *
	 * Body: `decisionType` (`amendment` or `withdrawal`), and optionally
	 * `rationale`, `decision`, `addressee` and `templateId`.
	 *
	 * @param string $id The beschikking being corrected or withdrawn.
	 *
	 * @return JSONResponse 201 with the successor; 401, 403, 404, 409 or 422 otherwise.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function successor(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$original = $this->decisionService->find($id);
		if ($original === null) {
			return new JSONResponse(['error' => 'Beschikking not found'], Http::STATUS_NOT_FOUND);
		}

		$caseId = (string)($original['caseId'] ?? '');
		if ($caseId === '' || $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'You may not change this case'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->request->getParams();

		try {
			$successor = $this->decisionService->issueSuccessor(
				originalId: $id,
				kind: (string)($body['decisionType'] ?? ''),
				overrides: $this->successorContentOf(body: $body),
				templateId: $this->successorTemplateOf(body: $body),
			);

			return new JSONResponse($successor, Http::STATUS_CREATED);
		} catch (RefusedException $refusal) {
			return $this->refused(op: 'successor', e: $refusal);
		} catch (Throwable $failure) {
			$this->logger->error(
				'BeschikkingController: successor failed',
				['exception' => $failure->getMessage(), 'beschikkingId' => $id],
			);

			return new JSONResponse(['error' => 'Could not complete the request'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end successor()

	/**
	 * The successor's content as the handler sent it, and nothing else.
	 *
	 * The number, the pointers and the status are never read from the
	 * body: compose() and the succession write them.
	 *
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return array<string, mixed> Rationale, decision and addressee, where sent.
	 */
	private function successorContentOf(array $body): array {
		$overrides = [];
		foreach (['rationale', 'decision', 'addressee'] as $field) {
			if (array_key_exists($field, $body) === true) {
				$overrides[$field] = $body[$field];
			}
		}

		return $overrides;
	}//end successorContentOf()

	/**
	 * The template the handler chose, or null to keep the original's.
	 *
	 * @param array<string, mixed> $body The request parameters.
	 *
	 * @return string|null The template id.
	 */
	private function successorTemplateOf(array $body): ?string {
		$templateId = trim((string)($body['templateId'] ?? ''));
		if ($templateId === '') {
			return null;
		}

		return $templateId;
	}//end successorTemplateOf()

	/**
	 * Resolve the current user UID or null.
	 *
	 * @return string|null
	 */
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end requireUser()

	/**
	 * Map a domain RuntimeException to a JSONResponse with an appropriate status.
	 *
	 * @param string $op The operation name (for logging).
	 * @param RuntimeException $e The exception.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntime(string $op, RuntimeException $e): JSONResponse {
		$code = $e->getMessage();
		$status = match ($code) {
			'not_found' => Http::STATUS_NOT_FOUND,
			'mandaat_insufficient' => Http::STATUS_FORBIDDEN,
			'immutable' => Http::STATUS_CONFLICT,
			'invalid_transition' => Http::STATUS_CONFLICT,
			'zaakId_required' => Http::STATUS_BAD_REQUEST,
			// LibreSign signing outcomes (libresign-besluit-signing).
			'libresign_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
			'libresign_signer_unresolvable' => Http::STATUS_UNPROCESSABLE_ENTITY,
			'libresign_signing_pending' => Http::STATUS_ACCEPTED,
			'libresign_signing_declined' => Http::STATUS_CONFLICT,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};

		$message = match ($code) {
			'not_found' => 'Beschikking not found',
			'mandaat_insufficient' => 'Insufficient mandaat for this decision',
			'immutable' => 'Beschikking is immutable in its current status',
			'invalid_transition' => 'Transition not allowed from the current status',
			'zaakId_required' => 'zaakId is required',
			'libresign_unavailable' => 'LibreSign is not available; install and enable the LibreSign app to sign this beschikking',
			'libresign_signer_unresolvable' => 'The signer could not be resolved to a Nextcloud account with a configured email address',
			'libresign_signing_pending' => 'Signature request created; awaiting the signer to complete signing in LibreSign',
			'libresign_signing_declined' => 'The signature request was declined or cancelled in LibreSign',
			default => 'Could not complete the request',
		};

		$this->logger->info('BeschikkingController: ' . $op . ' rejected', ['code' => $code]);
		return new JSONResponse(['error' => $message], $status);
	}//end mapRuntime()

	/**
	 * Log an unexpected failure and return a generic 500.
	 *
	 * @param string $op The operation name.
	 * @param \Throwable $e The exception.
	 *
	 * @return JSONResponse
	 */
	private function fail(string $op, \Throwable $e): JSONResponse {
		$this->logger->error(
			'BeschikkingController: ' . $op . ' failed',
			['exception' => $e->getMessage()],
		);
		return new JSONResponse(['error' => 'Could not complete the request'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end fail()

	/**
	 * Read and decode the JSON request body.
	 *
	 * @return array<string, mixed>
	 */
	private function readJsonBody(): array {
		// Prefer the request object's getContent() when reachable — test
		// stubs expose a public getContent() so unit tests can drive
		// controllers without faking php://input.
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
