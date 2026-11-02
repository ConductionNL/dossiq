<?php

/**
 * Reads an OpenRegister object update: the new object, the status it left, and whether it entered a status.
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

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use Throwable;

/**
 * The reads a listener on object updates shares.
 *
 * The using class has `$settingsService` (SettingsService) and `$logger`
 * (LoggerInterface) properties; a class that acts on a status entry also has
 * an ENTRY rule and a FAILURE_LOG prefix as constants.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
trait ObjectUpdatePayload {
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

	/**
	 * The id of the object an update moved into a status, or '' when the update did not.
	 *
	 * Only an update of an object in the configured schema counts, and only the
	 * transition itself: an object that already had the status does not.
	 *
	 * @param Event                 $event The dispatched event.
	 * @param array<string, string> $rule  `schemaKey` (the config key naming the schema) and `status`.
	 *
	 * @return string The object id, or ''.
	 */
	private function objectEnteringStatus(Event $event, array $rule): string {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return '';
		}

		$object = $this->updatedObject(event: $event);
		if ($object === null || $this->inConfiguredSchema(object: $object, schemaKey: $rule['schemaKey']) === false) {
			return '';
		}

		if ((string)($object['status'] ?? '') !== $rule['status'] || $this->previousStatus(event: $event) === $rule['status']) {
			return '';
		}

		return (string)($object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? '')));
	}//end objectEnteringStatus()

	/**
	 * Whether the object belongs to the schema a config key names.
	 *
	 * @param array<string, mixed> $object    The object payload.
	 * @param string               $schemaKey The config key naming the schema.
	 *
	 * @return bool
	 */
	private function inConfiguredSchema(array $object, string $schemaKey): bool {
		$schemaSlug = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($schemaSlug === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && ($candidate === $schemaSlug || str_ends_with($candidate, '/' . $schemaSlug));
	}//end inConfiguredSchema()

	/**
	 * The new object payload of an update event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function updatedObject(Event $event): ?array {
		foreach (['getNewObject', 'getObject'] as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$array = $this->payloadArray(value: $event->{$method}());
			if ($array !== null) {
				return $array;
			}
		}

		return null;
	}//end updatedObject()

	/**
	 * The status the object had before the update, or '' when unknown.
	 *
	 * @param Event $event The event.
	 *
	 * @return string
	 */
	private function previousStatus(Event $event): string {
		if (method_exists($event, 'getOldObject') === false) {
			return '';
		}

		$array = $this->payloadArray(value: $event->getOldObject());
		if ($array === null) {
			return '';
		}

		return (string)($array['status'] ?? '');
	}//end previousStatus()

	/**
	 * A getter's return value as an associative array.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function payloadArray(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === false) {
			return null;
		}

		foreach (['jsonSerialize', 'toArray'] as $method) {
			if (method_exists($value, $method) === true) {
				$array = $value->{$method}();
				if (is_array($array) === true) {
					return $array;
				}
			}
		}

		return null;
	}//end payloadArray()
}//end trait
