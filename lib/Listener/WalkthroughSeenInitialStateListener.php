<?php

/**
 * Dossiq onboarding: tell the frontend whether the welcome tour is behind the user.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Onboarding\WalkthroughCompletion;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Provides the `walkthrough_seen` initial state when dossiq's own page renders.
 *
 * The frontend (src/App.vue) reads it to hold the support note back until the welcome tour
 * is finished or skipped, so a new user meets the tour first (N-7). A
 * listener rather than a line in the page controller, for the reason
 * WooReportsInitialStateListener gives: that controller sits on the coupling
 * ceiling.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 */
class WalkthroughSeenInitialStateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IInitialState         $initialState The initial state of the page.
	 * @param WalkthroughCompletion $completion   Whether the caller has seen the tour.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly WalkthroughCompletion $completion,
	) {
	}//end __construct()

	/**
	 * Provide the answer on dossiq's own logged-in page only.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-load-onboarding/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false
			|| $event->isLoggedIn() === false
			|| $event->getResponse()->getApp() !== Application::APP_ID
		) {
			return;
		}

		$this->initialState->provideInitialState(WalkthroughCompletion::STATE_KEY, $this->completion->seen());
	}//end handle()
}//end class
