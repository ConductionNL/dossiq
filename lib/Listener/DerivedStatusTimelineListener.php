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
 * @spec openspec/specs/case-history-surface/spec.md
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
 * @spec openspec/specs/case-history-surface/spec.md
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
	 * @spec openspec/specs/case-history-surface/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$payload = $this->payload(entity: $event->getNewObject());
		if ($payload === null) {
			return;
		}

		$caseId = $this->caseIdOf(payload: $payload);
		if ($caseId === '') {
			return;
		}

		$staged = $this->landedMove(payload: $payload, caseId: $caseId);
		if ($staged === null) {
			return;
		}

		$this->recordMove(caseId: $caseId, staged: $staged);
	}//end handle()

	/**
	 * The case id of the saved payload, '' when it has none.
	 *
	 * @param array<string, mixed> $payload The saved case.
	 *
	 * @return string
	 */
	private function caseIdOf(array $payload): string {
		return (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
	}//end caseIdOf()

	/**
	 * The move the derivation staged for this case, when the save landed on it.
	 *
	 * @param array<string, mixed> $payload The saved case.
	 * @param string               $caseId  The case.
	 *
	 * @return array<string, string>|null The staged move, or null when none was staged or it did not land.
	 */
	private function landedMove(array $payload, string $caseId): ?array {
		$staged = $this->journal->take(caseId: $caseId);
		if ($staged === null || $this->landed(payload: $payload, staged: $staged) === false) {
			return null;
		}

		return $staged;
	}//end landedMove()

	/**
	 * Whether the save landed where the derivation asked.
	 *
	 * The save can have settled on something other than what the
	 * derivation asked for: a later listener, a refusal, a merge. A move
	 * that did not land is not a move to record.
	 *
	 * @param array<string, mixed>  $payload The saved case.
	 * @param array<string, string> $staged  The staged move: `from` and `to`.
	 *
	 * @return bool
	 */
	private function landed(array $payload, array $staged): bool {
		$landed = (string)($payload['status'] ?? '');
		if ($landed === $staged['to']) {
			return true;
		}

		$this->logger->debug(
			'Dossiq timeline: a derived move was staged but the case landed elsewhere, so nothing was recorded',
			['case' => $this->caseIdOf(payload: $payload), 'staged' => $staged['to'], 'landed' => $landed]
		);
		return false;
	}//end landed()

	/**
	 * Record the derived move on the case timeline.
	 *
	 * @param string                $caseId The case.
	 * @param array<string, string> $staged The staged move: `from` and `to`.
	 *
	 * @return void
	 */
	private function recordMove(string $caseId, array $staged): void {
		[$message, $visibility] = $this->announcement(staged: $staged);

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
			visibility: $visibility,
		);
	}//end recordMove()

	/**
	 * The sentence a derived move is recorded with, and who may read it.
	 *
	 * A derived move announces itself exactly as a made one does: in the
	 * public words the status carries, and only when it carries some. The
	 * reason it was derived is the workflow's business, so it does not
	 * travel with the public sentence.
	 *
	 * @param array<string, string> $staged The staged move: `from` and `to`.
	 *
	 * @return array{0: string, 1: string} The message and the timeline visibility.
	 */
	private function announcement(array $staged): array {
		$to = $this->statusName(statusTypeId: $staged['to']);
		$from = $this->statusName(statusTypeId: $staged['from']);

		$message = 'Status werd ' . $to . ' omdat de zaak daaraan voldoet';
		if ($from !== '') {
			$message = 'Status ging van ' . $from . ' naar ' . $to . ' omdat de zaak daaraan voldoet';
		}

		$publicLabel = $this->publicLabel(statusTypeId: $staged['to']);
		if ($publicLabel !== '') {
			return ['Status: ' . $publicLabel, CaseTimeline::PUBLIC_ENTRY];
		}

		return [$message, CaseTimeline::INTERNAL];
	}//end announcement()

	/**
	 * The words this status is announced to the applicant in, if any.
	 *
	 * Soft like {@see self::statusName()}: a lookup that cannot answer leaves
	 * the entry internal, which is the safe direction to fail in.
	 *
	 * @param string $statusTypeId The statusType uuid, or ''.
	 *
	 * @return string The public label, or ''.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function publicLabel(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		try {
			return trim($this->statuses->announcedLabelOf(statusTypeId: $statusTypeId));
		} catch (Throwable $e) {
			return '';
		}
	}//end publicLabel()

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
