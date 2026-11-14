<?php

/**
 * Dossiq event getters
 *
 * Reads a value off an event of another app, whatever its getter answers.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * Reads an array or a string off an event through a getter it may not have.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class EventGetters {

	/**
	 * Read an array off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return array<string, mixed> The value, or an empty array.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function arrayFrom(Event $event, string $method): array {
		if (method_exists($event, $method) === false) {
			return [];
		}

		$value = $event->$method();

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayFrom()

	/**
	 * Read a string off the event, whatever it answers, trimmed.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function stringFrom(Event $event, string $method): string {
		if (method_exists($event, $method) === false) {
			return '';
		}

		$value = $event->$method();

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end stringFrom()
}//end class
