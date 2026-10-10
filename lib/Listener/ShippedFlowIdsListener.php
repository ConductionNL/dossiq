<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Flow\ShippedFlowIds;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Puts the shipped flows' uuids into dossiq's page before it renders.
 *
 * A listener rather than a line in the page controller: the uuids are read
 * only when a dossiq page is about to render, and the controller does not
 * gain a collaborator for it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
 */
class ShippedFlowIdsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ShippedFlowIds $flowIds The uuids of shipped flows a manifest action names.
	 * @param IInitialState $initialState Dossiq's initial state.
	 */
	public function __construct(
		private readonly ShippedFlowIds $flowIds,
		private readonly IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Provide the uuids on a logged-in dossiq page, and nowhere else.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeTemplateRenderedEvent) === false || $event->isLoggedIn() === false) {
			return;
		}

		if ($event->getResponse()->getApp() !== Application::APP_ID) {
			return;
		}

		$this->flowIds->provide(initialState: $this->initialState);
	}//end handle()
}//end class
