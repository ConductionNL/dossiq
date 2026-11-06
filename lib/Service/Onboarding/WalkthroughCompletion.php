<?php

/**
 * Whether the signed-in user has finished or dismissed the welcome tour.
 *
 * A new user qualifies for two first-open overlays: the welcome tour and the
 * support note. The tour is onboarding, so it goes first, and the support note
 * waits for a later load (finding N-7). WalkthroughSeenInitialStateListener hands this answer
 * to the frontend as initial state, and `src/App.vue` keeps the support note
 * off on any load where the tour has not been seen yet.
 *
 * The answer is the per-user preference nextcloud-vue writes when the tour is
 * completed or skipped: the manifest's `walkthrough.completionConfigKey`,
 * stored by PreferencesController under the `pref_` prefix.
 * `WalkthroughCompletionTest` fails when the key here and the manifest's
 * drift apart.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Onboarding
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Onboarding;

use OCA\Dossiq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Reads whether the caller has seen the welcome tour.
 *
 * @spec openspec/specs/first-load-onboarding/spec.md#requirement-the-welcome-tour-comes-before-the-support-note-req-flo-001
 */
class WalkthroughCompletion {

	/**
	 * The initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'walkthrough_seen';

	/**
	 * The manifest's `walkthrough.completionConfigKey`.
	 *
	 * @var string
	 */
	public const PREFERENCE_KEY = 'walkthrough_completed_version';

	/**
	 * Constructor.
	 *
	 * @param IConfig      $config      Holds the per-user preference.
	 * @param IUserSession $userSession Names the caller.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether the caller has finished or skipped the welcome tour.
	 *
	 * A request with no user answers true, so nothing is held back for a
	 * page that has no tour to show first.
	 *
	 * @return bool True when the tour is behind them.
	 *
	 * @spec openspec/specs/first-load-onboarding/spec.md
	 */
	public function seen(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		$value = $this->config->getUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: 'pref_'.self::PREFERENCE_KEY,
			default: ''
		);

		return $value !== '';
	}//end seen()
}//end class
