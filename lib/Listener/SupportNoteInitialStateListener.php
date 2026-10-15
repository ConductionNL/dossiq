<?php

/**
 * Dossiq support note switch
 *
 * Provides the `support_note_shown` initial state when dossiq's own page
 * renders: whether the "Support Dossiq" note may open for a user on their
 * first load. An administrator switches it off with
 * `occ config:app:set dossiq show_support_note --value=false`.
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
 * @spec openspec/changes/the-support-note-can-be-switched-off/specs/first-load-onboarding/spec.md#requirement-an-administrator-can-switch-the-support-note-off-req-flo-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;

/**
 * Hands the frontend whether the support note may show.
 *
 * @spec openspec/changes/the-support-note-can-be-switched-off/specs/first-load-onboarding/spec.md#requirement-an-administrator-can-switch-the-support-note-off-req-flo-002
 *
 * @template-implements IEventListener<Event>
 */
class SupportNoteInitialStateListener implements IEventListener {

	/**
	 * The app config key that switches the note.
	 */
	public const CONFIG_KEY = 'show_support_note';

	/**
	 * The initial state key the frontend reads.
	 */
	public const STATE_KEY = 'support_note_shown';

	/**
	 * Values of the switch that mean off.
	 */
	private const OFF = ['false', '0', 'no', 'off'];

	/**
	 * Constructor.
	 *
	 * @param IInitialState $initialState Where the page's initial state is provided.
	 * @param IAppConfig    $appConfig    Where the switch is kept.
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Provide the switch on dossiq's own logged-in page.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-support-note-can-be-switched-off/specs/first-load-onboarding/spec.md#requirement-an-administrator-can-switch-the-support-note-off-req-flo-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false
			|| $event->isLoggedIn() === false
			|| $event->getResponse()->getApp() !== Application::APP_ID
		) {
			return;
		}

		$this->initialState->provideInitialState(self::STATE_KEY, $this->shown());
	}//end handle()

	/**
	 * Whether the note may show: on unless the switch says off.
	 *
	 * @return bool
	 */
	private function shown(): bool {
		try {
			$value = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, 'true');
		} catch (AppConfigTypeConflictException $e) {
			return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_KEY, true);
		}

		return in_array(strtolower(trim($value)), self::OFF, true) === false;
	}//end shown()
}//end class
