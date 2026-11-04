<?php

/**
 * Runs an act when an OpenRegister object update moves an object into a status.
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;
use Throwable;

/**
 * Acting on a status entry, for a listener with an ENTRY rule and a FAILURE_LOG prefix as constants.
 *
 * The using class has `$settingsService` (SettingsService) and `$logger`
 * (LoggerInterface) properties.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
trait ActsOnStatusEntry {
	use ObjectUpdatePayload;

	/**
	 * Run the act on the object an update moved into the class's ENTRY status.
	 *
	 * A failure is logged at debug level under the class's FAILURE_LOG and
	 * swallowed: the act must not block the transition that triggered it.
	 *
	 * @param Event    $event The dispatched event.
	 * @param callable $act   Takes the object id.
	 *
	 * @return void
	 */
	private function actOnStatusEntry(Event $event, callable $act): void {
		try {
			$objectId = $this->objectEnteringStatus(event: $event, rule: self::ENTRY);
			if ($objectId !== '') {
				$act($objectId);
			}
		} catch (Throwable $e) {
			$this->logger->debug(self::FAILURE_LOG . $e->getMessage());
		}
	}//end actOnStatusEntry()
}//end trait
