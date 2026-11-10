<?php

/**
 * The object an OpenRegister object event carries, as an array.
 *
 * The created event hands its object back from `getObject()`, as an array or
 * as an entity that serialises to one. Many listeners need that array and
 * nothing else; this reads it once.
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * Reads an event's `getObject()` as an array.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
final class EventObjectPayload {

	/**
	 * The event's object as an array, or null when it carries none that reads as one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public static function of(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		return self::fromValue(object: $event->getObject());
	}//end of()

	/**
	 * An array as it is, or what a serialisable entity serialises to when that is an array.
	 *
	 * @param mixed $object The event's object.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public static function fromValue(mixed $object): ?array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === false || method_exists($object, 'jsonSerialize') === false) {
			return null;
		}

		$serialized = $object->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end fromValue()
}//end class
