<?php

/**
 * Provides initial state only when the render is one of this app's own pages.
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
 * @spec exclude shared guard of the initial-state listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * Runs an initial-state provider for this app's own page renders only.
 *
 * @spec exclude shared guard of the initial-state listeners, covered by their own specs
 */
trait ProvidesOnOwnPage {
	/**
	 * Run the provider when the event renders one of this app's own pages.
	 *
	 * @param Event    $event   The render event.
	 * @param callable $provide Provides the initial state.
	 *
	 * @return void
	 */
	private function provideOnOwnPage(Event $event, callable $provide): void {
		if ((new OwnPageRender())->matches(event: $event) === false) {
			return;
		}

		$provide();
	}//end provideOnOwnPage()
}//end trait
