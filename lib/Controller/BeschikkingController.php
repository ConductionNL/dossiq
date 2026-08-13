<?php

/**
 * Procest Beschikking Controller.
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BeschikkingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for beschikking lifecycle endpoints.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */
class BeschikkingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param BeschikkingService $decisionService The beschikking service.
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BeschikkingService $decisionService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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

		$overrides = (array)($body['geadresseerde'] ?? []);
		$payload = (array)$body;

		if ($caseId === '') {
			return new JSONResponse(['error' => 'zaakId is required'], Http::STATUS_BAD_REQUEST);
		}

		$merged = [];
		if ($overrides !== []) {
			$merged['geadresseerde'] = $overrides;
		}

		foreach (['decisionType', 'rationale', 'beslissing'] as $field) {
			if (isset($payload[$field]) === true) {
				$merged[$field] = $payload[$field];
			}
		}

		try {
			$result = $this->decisionService->compose($caseId, $templateId, $merged);
			return new JSONResponse($result, Http::STATUS_CREATED);
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
		unset($updates['id'], $updates['huidigeStatus']);

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
