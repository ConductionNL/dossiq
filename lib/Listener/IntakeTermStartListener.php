<?php

/**
 * Stamp a new case with when it arrived and when its clock starts.
 *
 * A pure observer (ADR-022): the arithmetic is the engine calendar's, the
 * decision is {@see \OCA\Dossiq\Service\Intake\IntakeTermStart}'s, and this
 * class only carries the answer onto the record.
 *
 * 🔴 IT WRITES ONCE AND NEVER AGAIN. A case that already carries
 * `termStartsAt` is left alone, so a replayed create event cannot re-stamp it
 * against a calendar that has gained a holiday since. That is the whole of
 * "SHALL NOT be recomputed on read": the stamp is a record of what the citizen
 * was told, not a derivation of what today's calendar would say.
 *
 * The same write carries the case's start date and its type's receipt date
 * answer, when the case does not already carry them: the create form no
 * longer asks either (receipt-and-start-dates-are-set-at-creation).
 *
 * A DRAFT IS NOT RECEIVED YET (decision 180). A case saved in OpenRegister
 * status `draft` is a resident's half-filled form: it gets no stamp and no
 * dates. The save that moves it out of `draft` is the receipt, and that save
 * carries them, counted from the moment it was sent.
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

use OCA\Dossiq\Service\Intake\CaseReceiptDates;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two moments, written on the case the moment it exists.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class IntakeTermStartListener implements IEventListener {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart          $intake          The two moments and the flag between them.
	 * @param ObjectSchemaSlugResolver $slugResolver    Schema id-to-slug resolver.
	 * @param SettingsService          $settingsService Bridge to OpenRegister.
	 * @param LoggerInterface          $logger          Records a case that went unstamped.
	 * @param CaseReceiptDates         $receiptDates    The receipt and start dates a case is missing.
	 * @param ReceiptMoment            $receipt         Tells a receipt from a draft save.
	 * @param ITimeFactory             $time            The moment a draft is sent.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseReceiptDates $receiptDates,
		private readonly ReceiptMoment $receipt,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Handle a case-creating or case-created event, or the save that sends a draft.
	 *
	 * BEFORE THE CASE IS STORED (ObjectCreatingEvent) is where the fields are
	 * set. Two defects found live on 11 October 2026 came from writing them
	 * after it:
	 *
	 * 1. OpenRegister's materialised `startDate` calculation runs on the same
	 *    creating event and falls back to its own `now`, which it formats in
	 *    the process zone (UTC). A case made at 01:52 in Amsterdam was stored
	 *    with the day before, and a later write keeps what the case carries.
	 *    Registered above that calculation
	 *    ({@see \OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar::RECEIPT_DATES_PRIORITY}),
	 *    this listener sets the day in the administered zone first, and the
	 *    calculation keeps it.
	 * 2. The second write sent the whole case back, including materialised
	 *    readOnly fields another listener had already changed, and
	 *    OpenRegister refused it ("Cannot modify readOnly properties"). No
	 *    case got its stamp or its `receiptDate` answer.
	 *
	 * The created event stays as the fallback for a path that stores a case
	 * without the creating event (the bulk save). It writes only the fields
	 * it adds, through the partial write.
	 *
	 * A DRAFT IS SKIPPED on both create events (decision 180), and the update
	 * that moves a case out of `draft` is handled as the receipt: the fields
	 * are set on the case in that save, so the submit answer carries them.
	 * Any other update is left alone, which is what keeps the stamp written
	 * once.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — the stamp is what the citizen is
	 * told their term is, and it is read the instant the case exists: by the
	 * acknowledgement, by the term list and by the case page. Deferring it to a
	 * queue opens a window in which a case has no `termStartsAt`, and a case
	 * with no clock does not read as "not yet stamped", it reads as a case with
	 * no term. The work is bounded and it happens at most once per case: the
	 * payload is already in hand, `isStamped()` returns early on every replay,
	 * and what is left is one write.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function handle(Event $event): void {
		$entity = $this->receipt->objectBeingReceived(event: $event);
		if ($entity !== null) {
			$sentAt = '';
			if ($this->receipt->sendsDraft(event: $event) === true) {
				$sentAt = $this->time->now()->format('c');
			}

			$this->setBeforeStore(entity: $entity, sentAt: $sentAt);
			return;
		}

		// The created event, for a path that stored the case without the
		// creating event. A draft's own create is not a receipt there either,
		// and any other event answers null.
		$payload = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($payload === null) {
			return;
		}

		$caseId = trim((string)($payload['id'] ?? ($payload['uuid'] ?? '')));
		if ($caseId === '') {
			return;
		}

		$changes = $this->changesFor(case: $payload, sentAt: '');
		if ($changes === []) {
			return;
		}

		$this->write(caseId: $caseId, changes: $changes);
	}//end handle()

	/**
	 * Set the stamp and the dates on a case that is about to be stored.
	 *
	 * @param ObjectEntity $entity The case being saved.
	 * @param string       $sentAt The moment a draft was sent, ISO 8601; '' on a create.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	private function setBeforeStore(ObjectEntity $entity, string $sentAt): void {
		try {
			$payload = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq intake: a case being created could not be read',
				['exception' => $e->getMessage()],
			);
			return;
		}

		$changes = $this->changesFor(case: $payload, sentAt: $sentAt);
		if ($changes === []) {
			return;
		}

		$entity->setObject(array_merge($entity->getObject(), $changes));
	}//end setBeforeStore()

	/**
	 * The fields a case is missing for its stamp and its dates, or [].
	 *
	 * The stamp is written once. An empty stamp means the calendar did not
	 * answer, already logged by IntakeTermStart: nothing of it is written,
	 * because a half stamp is a claim. The receipt and start dates do not wait
	 * for the calendar: they are the day the request came in, which is known
	 * either way. Each is written only when the case does not carry it, so an
	 * import keeps its own and a replay writes nothing.
	 *
	 * A DRAFT THAT IS SENT names its own receipt: the moment it was sent, read
	 * first by {@see IntakeTermStart::arrivalOf()} as the case's `receivedAt`
	 * unless the draft already carries one. Its start date is written again:
	 * the one it carries is the day it was begun, filled in by the schema's
	 * own calculation, not a date anyone gave.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param string               $sentAt The moment a draft was sent, ISO 8601; '' for a create.
	 *
	 * @return array<string, mixed> The fields to set.
	 */
	private function changesFor(array $case, string $sentAt): array {
		if ($this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
			return [];
		}

		if ($sentAt !== '' && trim((string)($case[IntakeTermStart::RECEIVED_AT] ?? '')) === '') {
			$case[IntakeTermStart::RECEIVED_AT] = $sentAt;
		}

		$changes = [];
		if ($this->intake->isStamped(case: $case) === false) {
			$changes = $this->intake->stampForCase(case: $case);
		}

		// The start date follows the term start this case was just given, or
		// the one it already carries (decision 181, Q11).
		$termStartsAt = trim((string)($changes[IntakeTermStart::TERM_STARTS_AT] ?? ($case[IntakeTermStart::TERM_STARTS_AT] ?? '')));

		$changes = array_merge($changes, $this->receiptDates->changesFor(case: $case, termStartsAt: $termStartsAt));
		if ($sentAt !== '') {
			$changes[CaseReceiptDates::START_DATE] = $this->receiptDates->startDateFor(case: $case, termStartsAt: $termStartsAt);
		}

		return $changes;
	}//end changesFor()

	/**
	 * Write the missing fields onto the stored case, and nothing else.
	 *
	 * Only the fields this listener adds are sent. Sending the case as the
	 * event carried it also sent the materialised readOnly fields, which
	 * another listener may have changed since, and the store refused the
	 * whole write.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $changes The fields to add.
	 *
	 * @return void
	 */
	private function write(string $caseId, array $changes): void {
		try {
			$objectService = $this->settingsService->getObjectService();
			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('case_schema');
			if ($objectService === null || $register === '' || $schema === '') {
				return;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: $changes,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq intake: the term start could not be written onto the case',
				['case' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end write()
}//end class
