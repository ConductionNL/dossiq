<?php

/**
 * Dossiq AnswersCaseGestures support trait.
 *
 * The answer of a case gesture endpoint (claim, release, raise, clear): the
 * gesture's result, a refusal as a sentence and a static code, or a logged
 * 500. The using class provides `$logger`, the `REFUSAL_STATUS` map of code
 * to status, and `refusalSentence(string $code)`, whose sentence for an
 * unknown code is also the 500's.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use RuntimeException;

/**
 * Answers a case gesture: its result, its refusal, or a logged failure.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait AnswersCaseGestures {

	/**
	 * Run a gesture and answer it, turning a refusal into its status.
	 *
	 * @param string                           $caseId The case UUID.
	 * @param callable(): array<string, mixed> $run    The gesture.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $caseId, callable $run): JSONResponse {
		try {
			return new JSONResponse($run());
		} catch (RuntimeException $e) {
			return $this->refusal(code: $e->getMessage(), caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->error(
				$this->gestureController() . ': the gesture failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId]
			);

			return new JSONResponse(
				['error' => $this->refusalSentence(code: '')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end answer()

	/**
	 * One refusal, as a sentence a handler reads and a code a surface acts on.
	 *
	 * @param string $code   The rule that refused.
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $code, string $caseId): JSONResponse {
		$this->logger->info(
			$this->gestureController() . ': the gesture was refused',
			['code' => $code, 'caseId' => $caseId]
		);

		return new JSONResponse(
			['error' => $this->refusalSentence(code: $code), 'code' => $code],
			(self::REFUSAL_STATUS[$code] ?? Http::STATUS_BAD_REQUEST)
		);
	}//end refusal()

	/**
	 * The using controller's short class name, which the log lines open with.
	 *
	 * @return string
	 */
	private function gestureController(): string {
		$parts = explode('\\', static::class);

		return (string)end($parts);
	}//end gestureController()
}//end trait
