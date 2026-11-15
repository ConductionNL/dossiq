<?php

/**
 * Yields the objects an OpenRegister event carries, newest first.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use Generator;
use OCP\EventDispatcher\Event;

/**
 * The raw values of an event's object getters.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ReadsEventObjects {
	/**
	 * The values of getNewObject() and then getObject(), for the getters the event has.
	 *
	 * A generator, so a caller that stops at the first readable value never
	 * asks the next getter.
	 *
	 * @param Event $event The event.
	 *
	 * @return Generator<int, mixed>
	 */
	private function eventObjectValues(Event $event): Generator {
		foreach (['getNewObject', 'getObject'] as $method) {
			if (method_exists($event, $method) === true) {
				yield $event->{$method}();
			}
		}
	}//end eventObjectValues()
}//end trait
