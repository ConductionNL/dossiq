<?php

/**
 * Put a derived status move on the case's timeline, once it has landed.
 *
 * A status a handler picks writes a `statusRecord`, and `CaseStatusStore`
 * records that move on the timeline where all four of its callers meet. A
 * DERIVED status has no transition and writes no record: it is set inside the
 * save that made it true, by `DerivedStatusListener`, which deliberately
 * writes nothing durable because the save can still fail. Its own words:
 * "Putting the record back needs a post-persist listener that compares the two
 * statuses, which is the shape to reach for when the case timeline is asked to
 * show derivations." This is that listener.
 *
 * IT RECORDS ONLY WHAT WAS STAGED, AND THAT IS WHY IT CANNOT DOUBLE-WRITE.
 * Comparing the two statuses on the event alone would fire on every move,
 * including the four that already wrote their own entry through
 * `CaseStatusStore`, and the timeline would say twice what happened once. So
 * the pre-persist derivation stages what IT decided, on
 * `DerivedStatusJournal`, and this listener records only a staged move whose
 * target is the status that actually landed.
 *
 * THERE IS NO EXPLANATION, AND NONE IS INVENTED. Nobody explained a derived
 * move: the case became what the status describes. The entry carries the two
 * statuses and the name of the declaration, and leaves `explanation` empty
 * rather than filling it with a sentence no person wrote.
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
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a derived status move after the save that made it true.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class DerivedStatusTimelineListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DerivedStatusJournal $journal  What the derivation staged this request.
	 * @param CaseTimeline         $timeline The one seam that writes a timeline entry.
	 * @param StatusTypeLookup     $statuses Resolves a statusType id to its name.
	 * @param LoggerInterface      $logger   Structured logger.
	 */
	public function __construct(
		private readonly DerivedStatusJournal $journal,
		private readonly CaseTimeline $timeline,
		private readonly StatusTypeLookup $statuses,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the derived move a completed save carried.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$payload = $this->payload(entity: $event->getNewObject());
		if ($payload === null) {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$staged = $this->journal->take(caseId: $caseId);
		if ($staged === null) {
			return;
		}

		// The save can have settled on something other than what the
		// derivation asked for: a later listener, a refusal, a merge. A move
		// that did not land is not a move to record.
		$landed = (string)($payload['status'] ?? '');
		if ($landed !== $staged['to']) {
			$this->logger->debug(
				'Dossiq timeline: a derived move was staged but the case landed elsewhere, so nothing was recorded',
				['case' => $caseId, 'staged' => $staged['to'], 'landed' => $landed]
			);
			return;
		}

		$to = $this->statusName(statusTypeId: $staged['to']);
		$from = $this->statusName(statusTypeId: $staged['from']);

		$message = 'Status werd ' . $to . ' omdat de zaak daaraan voldoet';
		if ($from !== '') {
			$message = 'Status ging van ' . $from . ' naar ' . $to . ' omdat de zaak daaraan voldoet';
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::STATUS_CHANGE,
			message: $message,
			fields: [
				'from' => $staged['from'],
				'to' => $staged['to'],
				'actor' => '',
				'explanation' => '',
				'label' => 'Afgeleide status',
				'statusRecordId' => '',
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end handle()

	/**
	 * The administered name of a statusType, or its id when the name is not readable.
	 *
	 * @param string $statusTypeId The statusType uuid, or ''.
	 *
	 * @return string The name, the id, or '' when nothing was asked for.
	 */
	private function statusName(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		try {
			$name = trim($this->statuses->nameFor(statusTypeId: $statusTypeId));
		} catch (Throwable $e) {
			$name = '';
		}

		if ($name === '') {
			return $statusTypeId;
		}

		return $name;
	}//end statusName()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq timeline: the derived move could not read the saved case: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()
}//end class
