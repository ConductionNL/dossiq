<?php

/**
 * Work on a case moves its activity date; a system save does not.
 *
 * What counts as work: a status move, a note, a logged contact, a document
 * added to the case and a completed task. Each arrives through the seam every
 * surface crosses (the object event, the comment event, the engine's task
 * event), so the case page, the inbox and OpenRegister's own API all count.
 * A case save that leaves the status where it was (rematerialisation, an
 * example-set refresh, a derived field) is not work and does not count.
 *
 * The stamp is itself a case save with the status unchanged, so it does not
 * stamp again.
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Activity\CaseActivityStamp;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Stamps `lastActivityAt` on the case the work was done on.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
class CaseLastActivityListener implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The case schema's slug.
	 *
	 * @var string
	 */
	public const CASE_SCHEMA = 'case';

	/**
	 * The records whose creation on a case is work on it, by schema slug.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVITY_SCHEMAS = ['caseDocument', 'customerContact', 'contactmoment'];

	/**
	 * The comment object type OpenRegister files object notes under.
	 *
	 * @var string
	 */
	public const NOTE_OBJECT_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param CaseActivityStamp        $stamp        Writes the date onto the case.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves a schema id to its slug.
	 */
	public function __construct(
		private readonly CaseActivityStamp $stamp,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Stamp the case this event was work on, if it was.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity
	 */
	public function handle(Event $event): void {
		$caseId = $this->workedOnCase(event: $event);
		if ($caseId !== '') {
			$this->stamp->stamp(caseId: $caseId);
		}
	}//end handle()

	/**
	 * The case the event was work on, or '' when it was not work on a case.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function workedOnCase(Event $event): string {
		if ($event instanceof TaskTerminalEvent) {
			return $this->completedTaskCase(event: $event);
		}

		if ($event instanceof CommentsEvent) {
			return $this->notedObject(event: $event);
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) {
			return $this->objectCase(event: $event);
		}

		return '';
	}//end workedOnCase()

	/**
	 * The case of a saved object, when the save was work on it.
	 *
	 * A new case without an activity date counts as worked on when it was
	 * made; one that arrives with a date (an import, a seed) keeps it.
	 *
	 * @param Event $event An ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function objectCase(Event $event): string {
		$after = $this->savedObject(event: $event);
		if ($after === null) {
			return '';
		}

		$slug = $this->slugResolver->resolveFromPayload(payload: $after);
		$created = $event instanceof ObjectCreatedEvent;

		if ($slug === self::CASE_SCHEMA) {
			if ($created === true) {
				return $this->caseWithoutDate(after: $after);
			}

			return $this->caseWithMovedStatus(event: $event, after: $after);
		}

		if ($created === true && in_array($slug, self::ACTIVITY_SCHEMAS, true) === true) {
			return trim((string)($after['case'] ?? ''));
		}

		return '';
	}//end objectCase()

	/**
	 * A new case's id when it carries no activity date yet.
	 *
	 * @param array<string, mixed> $after The saved case.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function caseWithoutDate(array $after): string {
		if (trim((string)($after[CaseActivityStamp::FIELD] ?? '')) !== '') {
			return '';
		}

		return (string)$after['id'];
	}//end caseWithoutDate()

	/**
	 * An updated case's id when the save moved its status.
	 *
	 * An update without its before is not read as a move: every system
	 * re-save would otherwise count as work.
	 *
	 * @param Event                $event The update event.
	 * @param array<string, mixed> $after The saved case.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function caseWithMovedStatus(Event $event, array $after): string {
		$before = $this->previousObject(event: $event);
		if ($before === null || $this->unchanged(before: $before, after: $after, fields: ['status']) === true) {
			return '';
		}

		return (string)$after['id'];
	}//end caseWithMovedStatus()

	/**
	 * The object a new note was written on.
	 *
	 * @param CommentsEvent $event The comment event.
	 *
	 * @return string The object uuid, or ''.
	 */
	private function notedObject(CommentsEvent $event): string {
		try {
			$comment = $event->getComment();
			if ($event->getEvent() !== 'add' || $comment->getObjectType() !== self::NOTE_OBJECT_TYPE) {
				return '';
			}

			return trim($comment->getObjectId());
		} catch (Throwable) {
			return '';
		}
	}//end notedObject()

	/**
	 * The case a completed task was on.
	 *
	 * A task withdrawn (`terminated`, `disabled`) is not work done.
	 *
	 * @param TaskTerminalEvent $event The engine's terminal event.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function completedTaskCase(TaskTerminalEvent $event): string {
		try {
			$task = $event->getTask();
			if ($event->isCommitted() === false || (string)$task->getState() !== 'completed') {
				return '';
			}

			return trim((string)($task->getObjectUuid() ?? ''));
		} catch (Throwable) {
			return '';
		}
	}//end completedTaskCase()
}//end class
