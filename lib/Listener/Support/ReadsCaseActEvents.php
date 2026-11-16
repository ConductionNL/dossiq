<?php

/**
 * Reading the case act a comment or task event stands for.
 *
 * A new note on an object and a completed task on a case both count as an
 * act on that case. Listeners that react to acts read the same two answers
 * off the events; this trait gives them once.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec exclude shared event reading; each using listener carries its own spec
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use Throwable;

/**
 * The object a new note was written on, and the case a completed task was on.
 *
 * The using class declares `NOTE_OBJECT_TYPE`, the comment object type notes are written under.
 *
 * @spec exclude shared event reading; each using listener carries its own spec
 */
trait ReadsCaseActEvents {

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
}//end trait
