<?php

/**
 * Dossiq beschikking immutability listener.
 *
 * REQ-BES-008: a beschikking at `signed` or later is frozen. The rule was
 * already written and already enforced, but only inside
 * `BeschikkingService::updateFields()`, which was one method behind one route.
 * Under ADR-022 the frontend writes objects through OpenRegister's generic
 * object API, which never reaches that method, so the guard covered the door
 * nobody uses. This listener guards the store itself.
 *
 * It subscribes to OpenRegister's PRE-persist, stoppable events. The
 * post-persist pair cannot be used: OpenRegister dispatches
 * `ObjectUpdatedEvent` / `ObjectDeletedEvent` after the row has already been
 * written, with no surrounding transaction, so a listener there cannot stop
 * the mutation it objects to.
 *
 * `ObjectDeletingEvent` is NOT the delete path a caller normally takes. It is
 * dispatched only for a permanent delete; the ordinary DELETE, and the
 * referential-integrity cascade, both soft-delete by stamping the deletion
 * metadata on the entity and saving it, which dispatches
 * `ObjectUpdatingEvent`. Both shapes are handled here.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reject a content edit or a delete on a signed beschikking.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BeschikkingImmutabilityListener implements IEventListener {
	/**
	 * The error code a refused write carries back to the client.
	 *
	 * @var string
	 */
	private const ERROR_CODE = 'beschikking.immutable';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param StateMachineService $stateMachine Owns the REQ-BES-008 rule.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StateMachineService $stateMachine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist beschikking mutation and reject it when frozen.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspectUpdate(event: $event);
			return;
		}

		if ($event instanceof ObjectDeletingEvent === true) {
			$this->inspectDelete(event: $event);
			return;
		}
	}//end handle()

	/**
	 * Refuse an update that changes content on a frozen beschikking, or that
	 * soft-deletes one.
	 *
	 * The STORED state decides which fields may move. The incoming payload is
	 * consulted only for what it wants to change, never for what the status
	 * is: a payload claiming `draft` over a row that is `sent` is refused.
	 *
	 * A soft delete reaches this method, not `inspectDelete()`. OpenRegister
	 * dispatches `ObjectDeletingEvent` only on a PERMANENT delete
	 * (`DeleteObject::delete()` with `permanent: true`); the ordinary DELETE
	 * the generic object API serves stamps the deletion metadata on the entity
	 * and persists it through `ObjectEntityMapper::update()`, so it arrives
	 * here as an update that moves no content field at all. The cascade path
	 * (`DeleteObject::batchCascadeSoftDelete()`) has the same shape. Checking
	 * only the content diff therefore let a signed besluit be deleted.
	 *
	 * @param ObjectUpdatingEvent $event The pre-persist, stoppable event.
	 *
	 * @return void
	 */
	private function inspectUpdate(ObjectUpdatingEvent $event): void {
		$stored = $this->payload(object: $event->getOldObject());
		if ($stored === null || $this->isBeschikkingSchema(object: $stored) === false) {
			return;
		}

		$uuid = (string)$event->getOldObject()?->getUuid();

		$incoming = $this->payload(object: $event->getNewObject());
		if ($incoming === null) {
			// Fail CLOSED. A payload that cannot be read cannot be cleared
			// either, so a frozen beschikking refuses the write it is unable
			// to inspect rather than waving it through.
			if ($this->stateMachine->isImmutable(status: (string)($stored['currentStatus'] ?? '')) === true) {
				$this->reject(event: $event, uuid: $uuid);
			}

			return;
		}

		if ($this->isSoftDelete(stored: $stored, incoming: $incoming) === true) {
			try {
				$this->stateMachine->assertDeletable(stored: $stored);
			} catch (RuntimeException $rejection) {
				$this->reject(event: $event, uuid: $uuid);
			}

			return;
		}

		$changed = $this->changedContentFields(stored: $stored, incoming: $incoming);
		if ($changed === []) {
			return;
		}

		try {
			$this->stateMachine->assertMutable(stored: $stored, changed: $changed);
		} catch (RuntimeException $rejection) {
			$this->reject(event: $event, uuid: $uuid);
		}
	}//end inspectUpdate()

	/**
	 * Whether this update is the deletion marker being stamped on the row.
	 *
	 * @param array<string, mixed> $stored The state in the database.
	 * @param array<string, mixed> $incoming The state being written.
	 *
	 * @return bool True when the write turns a live row into a deleted one.
	 */
	private function isSoftDelete(array $stored, array $incoming): bool {
		return $this->isMarkedDeleted(object: $stored) === false
			&& $this->isMarkedDeleted(object: $incoming) === true;
	}//end isSoftDelete()

	/**
	 * Whether a payload carries OpenRegister's deletion metadata.
	 *
	 * The marker lives in `@self.deleted`, which `ObjectEntity::jsonSerialize()`
	 * builds from `setDeleted()`. The bare `deleted` fallback covers a payload
	 * handed over without the `@self` envelope.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when the object is marked deleted.
	 */
	private function isMarkedDeleted(array $object): bool {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			return empty($object['@self']['deleted']) === false;
		}

		return empty($object['deleted']) === false;
	}//end isMarkedDeleted()

	/**
	 * Refuse a delete on a frozen beschikking.
	 *
	 * @param ObjectDeletingEvent $event The pre-persist, stoppable event.
	 *
	 * @return void
	 */
	private function inspectDelete(ObjectDeletingEvent $event): void {
		$stored = $this->payload(object: $event->getObject());
		if ($stored === null || $this->isBeschikkingSchema(object: $stored) === false) {
			return;
		}

		try {
			$this->stateMachine->assertDeletable(stored: $stored);
		} catch (RuntimeException $rejection) {
			$this->reject(event: $event, uuid: (string)$event->getObject()->getUuid());
		}
	}//end inspectDelete()

	/**
	 * Stop the write and say why, naming the successor as the way forward.
	 *
	 * @param ObjectUpdatingEvent|ObjectDeletingEvent $event The stoppable event.
	 * @param string $uuid The beschikking uuid, for the log line.
	 *
	 * @return void
	 */
	private function reject(ObjectUpdatingEvent|ObjectDeletingEvent $event, string $uuid): void {
		$event->setErrors(
			[
				'message' => StateMachineService::IMMUTABLE_MESSAGE,
				'code' => self::ERROR_CODE,
			]
		);
		$event->stopPropagation();
		$this->logger->info(
			'Dossiq: rejected a mutation on a signed beschikking (REQ-BES-008)',
			['uuid' => $uuid]
		);
	}//end reject()

	/**
	 * Which content fields the incoming payload moves.
	 *
	 * A payload that repeats a stored value changes nothing, and the generic
	 * object API sends the whole object on every write, so presence alone
	 * would refuse a legitimate dispatch record.
	 *
	 * @param array<string, mixed> $stored The state in the database.
	 * @param array<string, mixed> $incoming The state being written.
	 *
	 * @return array<string, mixed> The content fields that differ, keyed by name.
	 */
	private function changedContentFields(array $stored, array $incoming): array {
		$changed = [];
		foreach (StateMachineService::CONTENT_FIELDS as $field) {
			if (array_key_exists($field, $incoming) === false) {
				continue;
			}

			if (($stored[$field] ?? null) === $incoming[$field]) {
				continue;
			}

			$changed[$field] = $incoming[$field];
		}

		return $changed;
	}//end changedContentFields()

	/**
	 * Read an object entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity|null $object The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(?ObjectEntity $object): ?array {
		if ($object === null) {
			return null;
		}

		try {
			return $object->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: beschikking immutability listener could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `beschikking` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a beschikking.
	 */
	private function isBeschikkingSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('beschikking_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isBeschikkingSchema()
}//end class
