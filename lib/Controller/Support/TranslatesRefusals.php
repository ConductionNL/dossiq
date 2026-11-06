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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Throwable;

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
				'message' => $this->refusalSentence(e: $e),
				'error' => $e->getRule(),
				'code' => $e->getMessage(),
			],
			$e->getStatus(),
		);
	}//end refusalResponse()

	/**
	 * The refusal's sentence in the reader's language.
	 *
	 * The sentence is authored in English, the catalogue key, and a handler
	 * reads it in their own language. Before this the English sentence went
	 * out as `message`, and only the lifecycle dialog mapped `code` back to a
	 * Dutch string, so every other surface showed a Dutch handler English.
	 * A sentence the catalogue does not know comes back unchanged.
	 *
	 * @param RefusedException $e The refusal.
	 *
	 * @return string The translated sentence, or the sentence as written.
	 *
	 * @spec openspec/specs/frontend-locale-completeness/spec.md
	 */
	private function refusalSentence(RefusedException $e): string {
		$sentence = $e->getSentence();
		$l10n = $this->refusalTranslator();
		if ($sentence === '' || $l10n === null) {
			return $sentence;
		}

		try {
			return (string)$l10n->t($sentence);
		} catch (Throwable) {
			// A sentence with a stray `%` cannot be formatted; the English
			// sentence is still a sentence, a 500 is not.
			return $sentence;
		}
	}//end refusalSentence()

	/**
	 * The translator for this request: the controller's own, else the app's.
	 *
	 * A controller that has its own translator overrides {@see self::refusalL10n()},
	 * which is also how a test hands one in. The app's translator from the factory
	 * follows the signed-in user's language. Null when neither is available,
	 * and then the sentence goes out as written.
	 *
	 * @return IL10N|null The translator.
	 *
	 * @spec openspec/specs/frontend-locale-completeness/spec.md
	 */
	private function refusalTranslator(): ?IL10N {
		$own = $this->refusalL10n();
		if ($own !== null) {
			return $own;
		}

		try {
			// The service locator, because a trait takes no constructor
			// injection. A controller that can inject `IL10N` overrides
			// refusalL10n() above and never reaches this line.
			return \OCP\Server::get(IFactory::class)->get(Application::APP_ID);
		} catch (Throwable) {
			return null;
		}
	}//end refusalTranslator()

	/**
	 * The controller's own translator, when it injects one.
	 *
	 * Null here. A controller that takes `IL10N` overrides this method; a
	 * method in the class wins over the trait's, so no controller has to
	 * declare a property the trait reads blind.
	 *
	 * @return IL10N|null The translator, or null to use the app's.
	 *
	 * @spec openspec/specs/frontend-locale-completeness/spec.md
	 */
	protected function refusalL10n(): ?IL10N {
		return null;
	}//end refusalL10n()
}//end trait
