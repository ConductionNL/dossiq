<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Dossiq\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Exception\RefusedException;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Turning a refusal into the response ADR-050 describes.
 *
 * Four controllers answer a refusal: BeschikkingController,
 * MandaatMatrixController, RoutingController and StatusTransitionController.
 * Each wrote the same three keys and the same log line, and each picked its
 * own HTTP status. That is how a case refused for being in the wrong status
 * and a case refused for a lost optimistic lock both left StatusTransition as
 * 400 "Could not execute transition", and how BeschikkingController's
 * `mapRuntime()` covered both a mandate that does not reach and a register
 * that could not be read with one static "insufficient mandaat" and no rule
 * slug at all.
 *
 * The status now comes from the refusal itself, along with the rule slug in
 * `error` and the sentence its author wrote in `message`. The log level
 * follows the status: an indeterminate answer is a warning, because something
 * is wrong that nobody asked for; a refusal the rules actually made is news
 * at info.
 */
trait TranslatesRefusals {
	/**
	 * Translate a refusal into its response, and log it at the level it deserves.
	 *
	 * @param string           $op The endpoint, for the log line.
	 * @param RefusedException $e  The refusal.
	 *
	 * @return JSONResponse The translated refusal, carrying the refusal's own status.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	private function refused(string $op, RefusedException $e): JSONResponse {
		$parts = explode('\\', static::class);
		$context = ['rule' => $e->getRule(), 'status' => $e->getStatus()];

		if ($e->getStatus() === RefusedException::STATUS_INDETERMINATE) {
			$this->logger->warning((string)end($parts) . ': ' . $op . ' could not be answered', $context);
			return $this->refusalResponse(e: $e);
		}

		$this->logger->info((string)end($parts) . ': ' . $op . ' refused', $context);

		return $this->refusalResponse(e: $e);
	}//end refused()

	/**
	 * The response body a refusal answers with, whatever its status.
	 *
	 * @param RefusedException $e The refusal.
	 *
	 * @return JSONResponse The rule slug, the sentence, and the refusal's own status.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	private function refusalResponse(RefusedException $e): JSONResponse {
		return new JSONResponse(
			[
				'message' => $e->getSentence(),
				'error' => $e->getRule(),
				'code' => $e->getMessage(),
			],
			$e->getStatus(),
		);
	}//end refusalResponse()
}//end trait
