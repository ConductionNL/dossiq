<?php

/**
 * Dossiq Frozen Record Listener.
 *
 * Enforces every configured freeze entry on OpenRegister's pre-persist
 * events, whichever door the write comes through, and seals the record on
 * the write that freezes it.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;
use OCA\Dossiq\Service\FrozenRecord\FreezeRegistry;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use OCA\Dossiq\Service\FrozenRecord\RecordSeal;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The call site of the frozen-record capability.
 *
 * With no `frozen_records` configured it reads one app-config value per
 * write and returns: a package switches the rule on for its record kind.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
class FrozenRecordListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FreezeRegistry $registry The configured freeze entries.
	 * @param FrozenRecordGuard $guard The freeze rule.
	 * @param RecordSeal $seal Seals the record as it freezes.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FreezeRegistry $registry,
		private readonly FrozenRecordGuard $guard,
		private readonly RecordSeal $seal,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse what the freeze does not allow; seal on the freezing write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->onUpdating(event: $event);
			return;
		}

		if ($event instanceof ObjectDeletingEvent === true) {
			$this->onDeleting(event: $event);
		}
	}//end handle()

	/**
	 * Inspect an update.
	 *
	 * @param ObjectUpdatingEvent $event The pre-persist, stoppable event.
	 *
	 * @return void
	 */
	private function onUpdating(ObjectUpdatingEvent $event): void {
		$stored = $this->payload(object: $event->getOldObject());
		if ($stored === null) {
			return;
		}

		$definition = $this->registry->forRecord(record: $stored);
		if ($definition === null) {
			return;
		}

		$incoming = $this->payload(object: $event->getNewObject());
		if ($incoming === null) {
			// Fail CLOSED: a write that cannot be read cannot be cleared either.
			if ($this->guard->isFrozen(definition: $definition, stored: $stored) === true) {
				$this->refuse(event: $event, refusal: $this->guard->refusal(definition: $definition));
			}

			return;
		}

		try {
			$this->guard->assertUpdatable(definition: $definition, stored: $stored, incoming: $incoming);
		} catch (RefusedException $refusal) {
			$this->refuse(event: $event, refusal: $refusal);
			return;
		}

		if ($definition->sealFields !== [] && $this->guard->isFreezing(definition: $definition, stored: $stored, incoming: $incoming) === true) {
			$this->sealOnFreeze(event: $event, definition: $definition, incoming: $incoming);
		}
	}//end onUpdating()

	/**
	 * Write the seal into the freezing write, keeping what other listeners modified.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $incoming The record as it freezes.
	 *
	 * @return void
	 */
	private function sealOnFreeze(ObjectUpdatingEvent $event, FreezeDefinition $definition, array $incoming): void {
		$modified = $event->getModifiedData();
		$record = array_merge($incoming, $modified);
		$modified[$definition->sealField] = $this->seal->seal(definition: $definition, record: $record);
		$event->setModifiedData($modified);
	}//end sealOnFreeze()

	/**
	 * Inspect a permanent delete.
	 *
	 * @param ObjectDeletingEvent $event The pre-persist, stoppable event.
	 *
	 * @return void
	 */
	private function onDeleting(ObjectDeletingEvent $event): void {
		$stored = $this->payload(object: $event->getObject());
		if ($stored === null) {
			return;
		}

		$definition = $this->registry->forRecord(record: $stored);
		if ($definition === null) {
			return;
		}

		try {
			$this->guard->assertDeletable(definition: $definition, stored: $stored);
		} catch (RefusedException $refusal) {
			$this->refuse(event: $event, refusal: $refusal);
		}
	}//end onDeleting()

	/**
	 * Stop the write and say why.
	 *
	 * @param ObjectUpdatingEvent|ObjectDeletingEvent $event The stoppable event.
	 * @param RefusedException $refusal The refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectUpdatingEvent|ObjectDeletingEvent $event, RefusedException $refusal): void {
		$event->setErrors(['error' => $refusal->getRule(), 'message' => $refusal->getSentence()]);
		$event->stopPropagation();
		$this->logger->info('Dossiq: refused a write on a frozen record', ['rule' => $refusal->getRule()]);
	}//end refuse()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity|null $object The entity.
	 *
	 * @return array<string, mixed>|null The payload.
	 */
	private function payload(?ObjectEntity $object): ?array {
		if ($object === null) {
			return null;
		}

		try {
			return $object->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq frozen records: a payload could not be read: ' . $e->getMessage());
			return null;
		}
	}//end payload()
}//end class
