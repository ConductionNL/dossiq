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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\CaseReceiptDates;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
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
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseReceiptDates $receiptDates,
	) {
	}//end __construct()

	/**
	 * Handle a case-creating or case-created event.
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
	 *    ({@see \OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar::RECEIPT_DATES_PRIORITY}),
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
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->setBeforeStore(entity: $event->getObject());
			return;
		}

		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		$caseId = trim((string)($payload['id'] ?? ($payload['uuid'] ?? '')));
		if ($caseId === '') {
			return;
		}

		$changes = $this->changesFor(case: $payload);
		if ($changes === []) {
			return;
		}

		$this->write(caseId: $caseId, changes: $changes);
	}//end handle()

	/**
	 * Set the stamp and the dates on a case that is about to be stored.
	 *
	 * @param ObjectEntity $entity The case being created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 */
	private function setBeforeStore(ObjectEntity $entity): void {
		try {
			$payload = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq intake: a case being created could not be read',
				['exception' => $e->getMessage()],
			);
			return;
		}

		$changes = $this->changesFor(case: $payload);
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
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The fields to set.
	 */
	private function changesFor(array $case): array {
		if ($this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
			return [];
		}

		$changes = [];
		if ($this->intake->isStamped(case: $case) === false) {
			$changes = $this->intake->stampForCase(case: $case);
		}

		return array_merge($changes, $this->receiptDates->changesFor(case: $case));
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

	/**
	 * The created object as an array, however the event carries it.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload.
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end extractObject()
}//end class
