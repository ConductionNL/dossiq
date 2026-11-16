<?php

/**
 * Answer an integriq event as the background service account when nobody is signed in.
 *
 * Integriq raises its events from its own background work, with nobody
 * signed in, and OpenRegister refuses a write from nobody. A signed-in
 * caller keeps writing as themselves. The using class provides
 * `$serviceAccount` ({@see BackgroundServiceAccount}).
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec exclude shared plumbing of the integriq listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;

/**
 * Runs a listener's answer as the service account when nobody is signed in.
 *
 * @spec exclude shared plumbing of the integriq listeners, covered by their own specs
 */
trait AnswersAsServiceAccount {

	/**
	 * Answer the event, as the service account when nobody is signed in.
	 *
	 * An unavailable service account is already logged as an error and told
	 * to the admins; nothing is written and nothing is thrown.
	 *
	 * @param callable(): mixed $answer The listener's answer to the event.
	 *
	 * @return void
	 */
	private function answerAsServiceAccount(callable $answer): void {
		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(operation: $answer);
		} catch (ServiceAccountUnavailableException $e) {
			return;
		}
	}//end answerAsServiceAccount()
}//end trait
