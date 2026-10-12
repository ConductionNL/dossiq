<?php

/**
 * Which save is the moment an object was received.
 *
 * Decision 180: a draft is the destination object itself, stored with the
 * OpenRegister lifecycle status `draft`. A draft has not arrived. It carries
 * no received moment, no term and no deadline, and nothing that answers
 * receipt (an acknowledgement, a statutory term, intake requirements) runs for
 * it. Receipt is one of two moments:
 *
 * - the create of an object that is not a draft, or
 * - the save that moves a draft out of `draft` (OpenRegister announces it
 *   afterwards as `ObjectActivatedEvent`).
 *
 * Every receipt listener asks this class, so the rule lives once.
 *
 * 🔴 NOTHING HERE NAMES THE EVENT CLASS AS A SYMBOL. `ObjectActivatedEvent`
 * ships with OpenRegister's draft lifecycle (openregister#4566). Referenced by
 * string, an instance without it simply never sends the event, and this app
 * still loads and analyses.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Tells a receipt from a draft save.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */
class ReceiptMoment {

	/**
	 * OpenRegister's lifecycle status for a saved, not yet sent object.
	 *
	 * @var string
	 */
	public const STATUS_DRAFT = 'draft';

	/**
	 * The event OpenRegister sends after a draft left `draft`.
	 *
	 * @var string
	 */
	public const ACTIVATED_EVENT = 'OCA\\OpenRegister\\Event\\ObjectActivatedEvent';

	/**
	 * Whether an object is a draft.
	 *
	 * Reads the entity's own `isDraft()` when it has one, and the serialised
	 * `@self.status` otherwise. An object with no stored status is never a
	 * draft: that is every object written before the status existed.
	 *
	 * @param mixed $object An ObjectEntity, or its serialised array.
	 *
	 * @return bool True for a draft.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function isDraft(mixed $object): bool {
		if (is_object($object) === true && method_exists($object, 'isDraft') === true) {
			return ($object->isDraft() === true);
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return false;
		}

		$self = ($object['@self'] ?? []);

		return (is_array($self) === true && ($self['status'] ?? null) === self::STATUS_DRAFT);
	}//end isDraft()

	/**
	 * Whether a save moves an object out of `draft`.
	 *
	 * @param mixed $before The object as stored, or null on a create.
	 * @param mixed $after  The object as it is being saved.
	 *
	 * @return bool True when the stored object was a draft and the saved one is not.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function leavesDraft(mixed $before, mixed $after): bool {
		if ($before === null) {
			return false;
		}

		return ($this->isDraft(object: $before) === true && $this->isDraft(object: $after) === false);
	}//end leavesDraft()

	/**
	 * The object a save is about to store, when that save is its receipt.
	 *
	 * The create of an object that is not a draft, or the update that sends a
	 * draft. A listener that sets fields in the receiving save works on this
	 * entity; any other save answers null and is left alone.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntity|null The object being stored, or null when this save receives nothing.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function objectBeingReceived(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatingEvent && $this->isDraft(object: $event->getObject()) === false) {
			return $event->getObject();
		}

		if ($this->sendsDraft(event: $event) === true) {
			return $event->getNewObject();
		}

		return null;
	}//end objectBeingReceived()

	/**
	 * Whether a save sends a draft: an update that moves the object out of `draft`.
	 *
	 * The draft was begun earlier and arrives now. Its creation moment is
	 * when the resident started typing, not when they pressed send.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool True for the update that sends a draft.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 *
	 * @psalm-assert-if-true ObjectUpdatingEvent $event
	 */
	public function sendsDraft(Event $event): bool {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return false;
		}

		return $this->leavesDraft(before: $event->getOldObject(), after: $event->getNewObject());
	}//end sendsDraft()

	/**
	 * The received object, when this event is a receipt.
	 *
	 * A create of a non-draft object, or OpenRegister's activation of a draft.
	 * A draft's own create answers null.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The serialised object, or null when this is no receipt.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function receivedObject(Event $event): ?array {
		$isCreate = ($event instanceof ObjectCreatedEvent);
		if ($isCreate === false && $this->isActivation(event: $event) === false) {
			return null;
		}

		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if ($isCreate === true && $this->isDraft(object: $object) === true) {
			return null;
		}

		return $this->serialised(object: $object);
	}//end receivedObject()

	/**
	 * An object as an array, however the event carries it.
	 *
	 * @param mixed $object An array, or an entity with `jsonSerialize()`.
	 *
	 * @return array<string, mixed>|null The array, or null.
	 */
	private function serialised(mixed $object): ?array {
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
	}//end serialised()

	/**
	 * The moment OpenRegister records for an activation, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return DateTimeImmutable|null The moment the draft left draft.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function activatedAt(Event $event): ?DateTimeImmutable {
		if ($this->isActivation(event: $event) === false || method_exists($event, 'getReceivedAt') === false) {
			return null;
		}

		$moment = $event->getReceivedAt();
		if ($moment instanceof DateTimeImmutable) {
			return $moment;
		}

		return null;
	}//end activatedAt()

	/**
	 * Whether the event is OpenRegister's activation of a draft.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool True for an ObjectActivatedEvent.
	 */
	private function isActivation(Event $event): bool {
		return is_a($event, self::ACTIVATED_EVENT);
	}//end isActivation()
}//end class
