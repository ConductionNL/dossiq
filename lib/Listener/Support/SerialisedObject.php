<?php

/**
 * An event object as an array.
 *
 * OpenRegister hands listeners an array, an entity that serialises to one, or
 * a value with a `toArray()`. Several listeners read the event's object the
 * same way; this reads it once.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * Reads an event's object as an array.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class SerialisedObject {

	/**
	 * The object one of the event's getters answers, as an array: the first getter that answers one.
	 *
	 * @param Event         $event   The dispatched event.
	 * @param array<string> $methods The getters to try, in order.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function fromEvent(Event $event, array $methods): ?array {
		foreach ($methods as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$object = self::of(value: $event->{$method}());
			if ($object !== null) {
				return $object;
			}
		}

		return null;
	}//end fromEvent()

	/**
	 * A value as an array: as it is, what it serialises to, or what its `toArray()` gives.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null Null when it is none of those.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === false) {
			return null;
		}

		$serialised = self::called(value: $value, method: 'jsonSerialize');
		if ($serialised !== null) {
			return $serialised;
		}

		return self::called(value: $value, method: 'toArray');
	}//end of()

	/**
	 * What one of the value's methods answers, when that is an array.
	 *
	 * @param object $value  The value.
	 * @param string $method The method.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private static function called(object $value, string $method): ?array {
		if (method_exists($value, $method) === false) {
			return null;
		}

		$answer = $value->{$method}();
		if (is_array($answer) === false) {
			return null;
		}

		return $answer;
	}//end called()
}//end class
