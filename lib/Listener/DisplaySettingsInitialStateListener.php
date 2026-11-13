<?php

/**
 * Dossiq DisplaySettingsInitialStateListener.
 *
 * Hands the page the date format and the beta switches the General tab saves
 * (board DqInstellingenAlgemeen), read on every page load so a change shows
 * on the next load without waiting for a cache.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Settings\SettingValueRules;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Provides `dateFormat` and `features` when dossiq's own page renders.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */
class DisplaySettingsInitialStateListener implements IEventListener {
	public const DEFAULT_DATE_FORMAT = 'DD-MM-YYYY';

	/**
	 * Constructor.
	 *
	 * @param IInitialState   $initialState The initial state of the page.
	 * @param SettingsServiceInterface $settings     The app config reader.
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly SettingsServiceInterface $settings,
	) {
	}//end __construct()

	/**
	 * Provide the settings on dossiq's own logged-in page only.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false
			|| $event->isLoggedIn() === false
			|| $event->getResponse()->getApp() !== AppIdentity::APP_ID
		) {
			return;
		}

		$format = $this->settings->getConfigValue('date_format', self::DEFAULT_DATE_FORMAT);
		if (in_array($format, SettingValueRules::CHOICES['date_format'], true) === false) {
			$format = self::DEFAULT_DATE_FORMAT;
		}

		$this->initialState->provideInitialState('dateFormat', $format);
		$newCasePage = ($this->settings->getConfigValue('feature_new_case_page', 'false') === 'true');
		$this->initialState->provideInitialState('features', ['newCasePage' => $newCasePage]);
	}//end handle()
}//end class
