<?php

/**
 * Dossiq Checklist Run Failure.
 *
 * The answer to a checklist run the store did not take, for
 * `TaskChecklistController::submit()`: OpenRegister's validation refusal is
 * the caller's fault (400, logged at info), this app's own refusal is a 422,
 * and anything else is a genuine fault (500, logged as an error).
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Turns a failed checklist run into its HTTP answer.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ChecklistRunFailure {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger  Logger.
	 * @param string          $appName The app name the log lines carry.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $appName,
	) {
	}//end __construct()

	/**
	 * The answer to a checklist run the store did not take.
	 *
	 * OpenRegister's validation refusal is the caller's fault (400, logged at
	 * info); this app's own refusal is a 422; anything else is a logged 500.
	 *
	 * @param string    $caseId    The case.
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function answer(string $caseId, Throwable $exception): JSONResponse {
		if ($exception instanceof OpenRegisterValidationException
			|| $exception instanceof OpenRegisterCustomValidationException
		) {
			// A REJECTED PAYLOAD IS THE CALLER'S FAULT, NOT THE SERVER'S.
			//
			// OpenRegister validates every write against the schema and throws
			// its own ValidationException with a message that already names the
			// offending property -- `Property 'checklist' should match format
			// 'uuid' but 'e2e-checklist' does not`. Without this arm that
			// exception fell through to the `Throwable` arm below and came
			// back as a 500, so a caller sending a bad payload was told the
			// server had broken and every such submission read as an outage.
			// It stayed invisible because the e2e citation covering this
			// endpoint asserted `not.toBe(403)`, which a 500 satisfies.
			//
			// 400 rather than the 422 the RuntimeException arm returns, because
			// this is OpenRegister's verdict on OpenRegister's schema and
			// OpenRegister answers 400 for it on its own endpoints
			// (ValidateObject::handleValidationException); the two APIs should
			// not disagree about the same rejection. 422 stays for the checks
			// this app makes itself, such as a required photo that is missing.
			//
			// NARROW ON PURPOSE. Widening the arm below to return 4xx for every
			// Throwable would make the symptom go away by reporting real server
			// faults as the caller's fault, which is the worse failure: a
			// genuine fault must still be a 500 and must still be logged as an
			// error, and it is, immediately below.
			return $this->rejectedPayload(caseId: $caseId, exception: $exception);
		}

		if ($exception instanceof RuntimeException) {
			return new JSONResponse(
				['message' => $exception->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->serverFault(caseId: $caseId, exception: $exception);
	}//end answer()

	/**
	 * OpenRegister's validation refusal: a 400 logged at info.
	 *
	 * @param string    $caseId    The case.
	 * @param Throwable $exception The validation refusal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function rejectedPayload(string $caseId, Throwable $exception): JSONResponse {
		$this->logger->info(
			'Rejected checklist run for case ' . $caseId . ': ' . $exception->getMessage(),
			['app' => $this->appName]
		);
		return new JSONResponse(
			['message' => $exception->getMessage()],
			Http::STATUS_BAD_REQUEST
		);
	}//end rejectedPayload()

	/**
	 * A genuine fault: a 500 logged as an error.
	 *
	 * @param string    $caseId    The case.
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function serverFault(string $caseId, Throwable $exception): JSONResponse {
		$this->logger->error(
			'Failed to submit a checklist run for case ' . $caseId . ': ' . $exception->getMessage(),
			['app' => $this->appName]
		);
		return new JSONResponse(
			['message' => 'Submission failed: ' . $exception->getMessage()],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end serverFault()
}//end class
