<?php

/**
 * One thing waiting on one person.
 *
 * A queue item POINTS AT work, it is not the work. Nothing here is stored:
 * every field is read from the mechanism that already owns the subject, so a
 * case that closes, a task that completes and a consultation that is answered
 * take their item with them without a second write. That is the whole reason
 * an item carries `subjectType` and `subjectId` rather than a copy of the
 * subject: the queue can always go back and ask.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use JsonSerializable;

/**
 * A single entry on a person's queue.
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
final class QueueItem implements JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param string      $source      The declared source that produced this item.
	 * @param string      $subjectType What the item points at: case, task, consultation, decision, mention, plannedItem.
	 * @param string      $subjectId   The subject's identifier in the mechanism that owns it.
	 * @param string      $title       What the reader sees.
	 * @param string      $priority    One of the case priority words, or an empty string when the subject has none.
	 * @param string|null $dueAt       The date the subject is due, `Y-m-d`, or null.
	 * @param string|null $coveredFor  The absent colleague this work belongs to, or null when it is the reader's own.
	 * @param array       $route       The vue-router location that opens the subject.
	 * @param array       $waiting     Who this subject is waiting on, since when, and how many
	 *                                 reminders have gone out. Empty when nobody is waiting.
	 *                                 Last in the list on purpose: every existing caller keeps
	 *                                 working unchanged, and only a source that knows the answer
	 *                                 passes one.
	 * @param array       $subject     The subject row as its mechanism answers it NOW, or empty
	 *                                 when the source did not read one. `QueueItemLifecycle` needs
	 *                                 it to say whether the item still stands, and it is carried on
	 *                                 the item rather than fetched again because the source has just
	 *                                 read it: fetching it twice would double the reads behind every
	 *                                 queue and could disagree with itself between the two.
	 *                                 EMPTY IS NOT "GONE". A source that passes nothing is saying it
	 *                                 does not know, and the queue leaves its items alone; only a
	 *                                 source that DID read the subject can have its items dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $subjectType,
		public readonly string $subjectId,
		public readonly string $title,
		public readonly string $priority = '',
		public readonly ?string $dueAt = null,
		public readonly ?string $coveredFor = null,
		public readonly array $route = [],
		public readonly array $waiting = [],
		public readonly array $subject = [],
	) {
	}//end __construct()

	/**
	 * The item's identity.
	 *
	 * Derived from the source and the subject rather than generated, so the
	 * same waiting work is the same item on every read. A generated id would
	 * make "hide this group for today" and "this item is already on screen"
	 * both unanswerable across two page loads.
	 *
	 * @return string The stable id.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function identity(): string {
		return $this->source . ':' . $this->subjectType . ':' . $this->subjectId;
	}//end identity()

	/**
	 * Whether this item is somebody else's work the reader is covering.
	 *
	 * @return bool TRUE when the item arrived through a substitution.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function isCovered(): bool {
		return ($this->coveredFor !== null && trim($this->coveredFor) !== '');
	}//end isCovered()

	/**
	 * The item as the queue endpoint answers it.
	 *
	 * @return array<string, mixed> The wire shape.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->identity(),
			'source' => $this->source,
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'title' => $this->title,
			'priority' => $this->priority,
			'dueAt' => $this->dueAt,
			'coveredFor' => $this->coveredFor,
			'covered' => $this->isCovered(),
			'route' => $this->route,
			'waiting' => $this->waiting,
		];
	}//end jsonSerialize()
}//end class
