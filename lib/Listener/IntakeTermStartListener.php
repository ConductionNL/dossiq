<?php

/**
 * Stamp a case, inside the save that receives it, with when it arrived and when its clock starts.
 *
 * A pure observer (ADR-022): the arithmetic is the engine calendar's, the
 * decision is {@see \OCA\Dossiq\Service\Intake\IntakeTermStart}'s, and this
 * class only carries the answer onto the record.
 *
 * 🔴 THE STAMP IS PART OF THE CREATE, NOT A SECOND SAVE (decision 179). It
 * listens to OpenRegister's pre-persist events, so the create answer a form
 * submit returns already holds `receivedAt`, `termStartsAt` and
 * `receivedOutsideWorkingHours`, and the audit trail holds one create instead
 * of a create and an update. It used to listen to `ObjectCreatedEvent` and
 * save again, which a caller reading its own save result never saw.
 *
 * 🔴 A DRAFT IS NOT RECEIVED (decision 180). A case in OpenRegister status
 * `draft` gets no stamp. The save that moves it out of `draft` is the receipt,
 * and it is stamped then, with the moment of that save.
 *
 * 🔴 IT WRITES ONCE AND NEVER AGAIN. A case that already carries
 * `termStartsAt` is left alone, so a replayed save cannot re-stamp it against
 * a calendar that has gained a holiday since.
 *
 * 🔴 IT RUNS BEFORE OPENREGISTER'S CALCULATIONS. `startDate` follows the term
 * start (decision 181) and the fallback `deadline` is `startDate` plus the
 * processing term, materialised by OpenRegister's own pre-persist listener.
 * The registrar gives this listener a higher priority so that listener reads
 * the start date written here, and it writes onto the entity itself because
 * that listener reads the entity, not the event's modified data.
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
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two moments, written on the case in the save that receives it.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 *
 * @template-implements IEventListener<Event>
 */
class IntakeTermStartListener implements IEventListener {

	/**
	 * The listener priority: above OpenRegister's calculation listener (0).
	 *
	 * @var int
	 */
	public const PRIORITY = 10;

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart          $intake       The two moments and the flag between them.
	 * @param ReceiptMoment            $receipt      Tells a receipt from a draft save.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param ITimeFactory             $time         The moment a draft is sent.
	 * @param LoggerInterface          $logger       Records a payload that could not be read.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly ReceiptMoment $receipt,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp a case being created, or a draft case being sent.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness: the stamp is what the citizen is
	 * told their term is, and the submit answer reads it the instant the case
	 * exists. The work is bounded, pure arithmetic on the payload in hand.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
			if ($this->receipt->isDraft(object: $entity) === true) {
				return;
			}

			$this->stamp(event: $event, entity: $entity, sentAt: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			if ($this->receipt->leavesDraft(before: $event->getOldObject(), after: $entity) === false) {
				return;
			}

			// The draft was begun earlier; it arrives now. Its creation moment
			// is when the resident started typing, not when they pressed send.
			$sentAt = DateTimeImmutable::createFromMutable($this->time->getDateTime());
			$this->stamp(event: $event, entity: $entity, sentAt: $sentAt);
		}
	}//end handle()

	/**
	 * Put the three fields, and a start date when none was given, on the case being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being saved.
	 * @param DateTimeImmutable|null                  $sentAt The moment a draft was sent; null on a create.
	 *
	 * @return void
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity, ?DateTimeImmutable $sentAt): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$payload = array_merge($payload, $event->getModifiedData());
		if ($this->intake->isStamped(case: $payload) === true) {
			return;
		}

		$receivedAt = ($sentAt ?? $this->intake->arrivalOf(case: $payload));
		$changes = $this->intake->stampFor(receivedAt: $receivedAt);
		if ($changes === []) {
			// Already logged by IntakeTermStart, naming the moment it could
			// not place. Nothing is written: a half stamp is a claim.
			return;
		}

		$changes = array_merge($changes, $this->startDate(payload: $payload, stamp: $changes));

		// Onto the entity, so OpenRegister's calculation listener sees the
		// start date; and onto the event, which is what the mapper merges last.
		$entity->setObject(array_merge($entity->getObject(), $changes));
		$event->setModifiedData(array_merge($event->getModifiedData(), $changes));
	}//end stamp()

	/**
	 * The start date this case takes from its term start (decision 181).
	 *
	 * Only when the case carries none. A handler who files a case gives the
	 * start date, and that date stays. A form submit gives none, so its start
	 * date is the date the term starts and the one deadline rule
	 * (`startDate` plus the processing term) counts from the same day the
	 * confirmation names.
	 *
	 * @param array<string, mixed> $payload The case being saved.
	 * @param array<string, mixed> $stamp   The three fields.
	 *
	 * @return array<string, string> `startDate`, or nothing.
	 */
	private function startDate(array $payload, array $stamp): array {
		if (trim((string)($payload['startDate'] ?? '')) !== '') {
			return [];
		}

		try {
			$startsAt = new DateTimeImmutable((string)$stamp[IntakeTermStart::TERM_STARTS_AT]);
		} catch (Throwable $e) {
			return [];
		}

		return ['startDate' => $startsAt->format('Y-m-d')];
	}//end startDate()

	/**
	 * The case being saved, as an array.
	 *
	 * @param ObjectEntity $entity The case.
	 *
	 * @return array<string, mixed>|null The payload, or null when it cannot be read.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq intake: the case payload could not be read: ' . $e->getMessage());
			return null;
		}
	}//end payload()
}//end class
