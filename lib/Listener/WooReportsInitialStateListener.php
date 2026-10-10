<?php

/**
 * Dossiq Woo review reports: tell the menu which reports this user is offered.
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
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Provides the `woo_reports` initial state when dossiq's own page renders.
 *
 * The menu entry of the Woo reports page reads it (`visibleIf` on
 * `woo.throughputReader`), so the screen is offered only while the report is
 * on and the user is in its reader group. The route checks both again on
 * every read; this only decides what the menu shows.
 *
 * A listener rather than a line in the page controller: that controller sits
 * on the coupling ceiling, and the offer is the reports' business, not the page's.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
class WooReportsInitialStateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IInitialState $initialState The initial state of the page.
	 * @param WooReportSwitches $switches The report switches.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly WooReportSwitches $switches,
	) {
	}//end __construct()

	/**
	 * Provide the offer on dossiq's own logged-in page only.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent === false
			|| $event->isLoggedIn() === false
			|| $event->getResponse()->getApp() !== Application::APP_ID
		) {
			return;
		}

		$this->initialState->provideInitialState('woo_reports', $this->switches->offeredToCurrentUser());
	}//end handle()
}//end class
