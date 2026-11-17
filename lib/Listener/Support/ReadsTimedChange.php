<?php

/**
 * The saved object of a timer listener's event, when a timed field changed.
 *
 * The using class provides `$settingsService`, the `TIMED_FIELDS` constant and
 * the SavedObjectPayload steps.
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
 * @spec exclude shared read of several timer listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * Reads the saved object when it is in the configured schema and a timed field changed.
 *
 * @spec exclude shared read of several timer listeners, covered by their own specs
 */
trait ReadsTimedChange {
	/**
	 * The saved object, or null when there is none, it is outside the schema or no timed field changed.
	 *
	 * @param Event  $event     The save event.
	 * @param string $schemaKey The configuration key of the schema the object must be in.
	 *
	 * @return array<string, mixed>|null
	 */
	private function timedChange(Event $event, string $schemaKey): ?array {
		$saved  = $this->savedObject(event: $event);
		$schema = (string) $this->settingsService->getConfigValue($schemaKey);
		if ($saved === null
			|| $this->inSchema(object: $saved, configured: $schema) === false
			|| $this->unchanged(before: $this->previousObject(event: $event), after: $saved, fields: self::TIMED_FIELDS) === true
		) {
			return null;
		}

		return $saved;
	}//end timedChange()
}//end trait
