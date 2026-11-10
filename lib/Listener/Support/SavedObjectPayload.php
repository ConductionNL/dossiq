<?php

/**
 * Reading a saved OpenRegister object off an object event.
 *
 * The created event names its getter `getObject()`, the updated event
 * `getNewObject()` and `getOldObject()`, and each hands back an entity.
 * Listeners that sync something to a saved object need the same three
 * answers: the object after the save, the object before it, and whether it
 * belongs to a configured schema. This trait gives them once.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCP\EventDispatcher\Event;

/**
 * The saved object, the object before the save, and its schema.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
trait SavedObjectPayload {

	/**
	 * The object after the save, with a top-level id.
	 *
	 * @param Event $event An ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return array<string, mixed>|null
	 */
	private function savedObject(Event $event): ?array {
		$entity = $this->eventValue(event: $event, method: 'getObject') ?? $this->eventValue(event: $event, method: 'getNewObject');

		return $this->objectArray(entity: $entity);
	}//end savedObject()

	/**
	 * The object before the save, null on a create.
	 *
	 * @param Event $event An object event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function previousObject(Event $event): ?array {
		return $this->objectArray(entity: $this->eventValue(event: $event, method: 'getOldObject'));
	}//end previousObject()

	/**
	 * Whether the save left these fields as they were.
	 *
	 * A create has no before, so it always counts as a change.
	 *
	 * @param array<string, mixed>|null $before The object before the save.
	 * @param array<string, mixed>      $after  The object after it.
	 * @param string[]                  $fields The fields that matter.
	 *
	 * @return bool True when every field is unchanged.
	 */
	private function unchanged(?array $before, array $after, array $fields): bool {
		if ($before === null) {
			return false;
		}

		foreach ($fields as $field) {
			if (json_encode($before[$field] ?? null) !== json_encode($after[$field] ?? null)) {
				return false;
			}
		}

		return true;
	}//end unchanged()

	/**
	 * Whether the object belongs to the schema a config key names.
	 *
	 * @param array<string, mixed> $object    The object payload.
	 * @param string               $configured The configured schema id or slug.
	 *
	 * @return bool
	 */
	private function inSchema(array $object, string $configured): bool {
		if ($configured === '') {
			return false;
		}

		$candidate = (string) ($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && ($candidate === $configured || str_ends_with($candidate, '/'.$configured));
	}//end inSchema()

	/**
	 * Call an event getter when the event has it.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return mixed The value, or null.
	 */
	private function eventValue(Event $event, string $method): mixed {
		if (method_exists($event, $method) === false) {
			return null;
		}

		return $event->{$method}();
	}//end eventValue()

	/**
	 * An entity or array as the object's array, with a top-level id.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function objectArray(mixed $entity): ?array {
		$data = null;
		if (is_array($entity) === true) {
			$data = $entity;
		} else if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				$data = $serialized;
			}
		}

		if ($data === null) {
			return null;
		}

		$data['id'] = (string) ($data['id'] ?? ($data['@self']['id'] ?? ''));
		return $data;
	}//end objectArray()
}//end trait
