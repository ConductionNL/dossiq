<?php

/**
 * Dossiq advice writes.
 *
 * The advice writes a handler makes from the case (move a request to another
 * status, ask for advice, send a reminder), each answered as a response: a
 * refusal of the advice service as the caller's to fix, anything else logged
 * and answered as a 500. Split out of
 * {@see \OCA\Dossiq\Controller\AdviceController}, which keeps the session
 * and the per-case guards.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/advice-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\AdviceServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Makes an advice write and answers its outcome.
 *
 * @spec openspec/specs/advice-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AdviceWrites {

	/**
	 * Constructor.
	 *
	 * @param AdviceServiceInterface $adviceService The advice service.
	 * @param LoggerInterface        $logger        Logs a write that failed.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly AdviceServiceInterface $adviceService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a manual reminder to the adviseur, through the authorized seam.
	 *
	 * @param string $id The advice UUID.
	 *
	 * @return JSONResponse `reminded`; 403 with the service's refusal; 500 without the internal message.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function remind(string $id): JSONResponse {
		try {
			// Authorized seam. The unguarded `dispatchReminder()` behind it is
			// the cron's, and is not reachable from HTTP.
			$this->adviceService->dispatchReminderAsUser($id);
			return new JSONResponse(['status' => 'reminded']);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq: advice dispatchReminder failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'Could not send reminder'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end remind()

	/**
	 * Move an advice request to a status, answering the record or the error.
	 *
	 * @param string               $id   The advice UUID
	 * @param string               $to   The target status.
	 * @param array<string, mixed> $data The request body.
	 *
	 * @return JSONResponse Updated record
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function transition(string $id, string $to, array $data): JSONResponse {
		try {
			$advice = $this->adviceService->transitionStatus($id, $to, $data);
			return new JSONResponse($advice);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq: advice transition failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Could not transition advice request'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end transition()

	/**
	 * Create the advice request from the request body, answering a refusal or failure as a response.
	 *
	 * @param string               $id   UUID of the case
	 * @param string               $uid  The requesting user, already allowed to mutate the case
	 * @param array<string, mixed> $data The request body
	 *
	 * @return JSONResponse Created advice request or error
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function request(string $id, string $uid, array $data): JSONResponse {
		$data['caseRef'] = $id;
		$data['requestedBy'] = $uid;
		$data['status'] = 'open';

		try {
			$advice = $this->adviceService->requestAdvice(caseId: $id, data: $data, requestedBy: $uid);
			return new JSONResponse(data: $advice, statusCode: Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			// A date the system cannot read is the caller's to fix, and nothing
			// is created. It used to be stored verbatim.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->adviceRequestFailure(error: $e, id: $id);
		}
	}//end request()

	/**
	 * Log a failed advice request and answer it as a server error.
	 *
	 * @param \Throwable $error The failure.
	 * @param string     $id    UUID of the case
	 *
	 * @return JSONResponse The error.
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function adviceRequestFailure(\Throwable $error, string $id): JSONResponse {
		$this->logger->error(
			'Failed to create advice request for case ' . $id . ': ' . $error->getMessage(),
			['app' => AppIdentity::APP_ID]
		);
		return new JSONResponse(
			['error' => 'Could not create advice request: ' . $error->getMessage()],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end adviceRequestFailure()
}//end class
