<?php

/**
 * Dossiq Mention Notification Service.
 *
 * Turns a saved note's `@mention` tokens (nc-vue #207, `CnNotesTab`'s
 * `mention` event) into real Nextcloud notifications for each mentioned
 * user. Built on Nextcloud's IManager with the imperative
 * create/notify/try-catch-warn shape.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Notes\MentionedNote;
use OCA\Dossiq\Service\Notes\NoteMention;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCA\Dossiq\Service\Queue\MentionQueueRecords;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Nextcloud notifications for note `@mention`s.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class MentionNotificationService {
	/**
	 * Constructor.
	 *
	 * @param IManager $notificationManager The Nextcloud notification manager
	 * @param MentionQueueRecords $queueRecords The per-person record a queue can wait on
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly MentionQueueRecords $queueRecords,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Notify every mentioned user, skipping the note's own author.
	 *
	 * @param string $actorUserId The note author's user id
	 * @param string $actorDisplayName The note author's display name
	 * @param MentionedNote $note The note, the object it is attached to and where that lives
	 * @param array<string> $mentionedUserIds The mentioned users' NC user ids
	 *
	 * @return int Number of notifications actually dispatched
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function notifyMention(
		string $actorUserId,
		string $actorDisplayName,
		MentionedNote $note,
		array $mentionedUserIds,
	): int {
		$mention = new NoteMention(actorUserId: $actorUserId, actorDisplayName: $actorDisplayName, note: $note);
		$notified = 0;

		foreach (array_unique($mentionedUserIds) as $mentionedUserId) {
			// Never notify authors about their own mentions (e.g. self-mention,
			// or a duplicate @mention of the same user typed twice).
			if ($mentionedUserId === '' || $mentionedUserId === $actorUserId) {
				continue;
			}

			if ($this->notifyOne(mention: $mention, recipient: $mentionedUserId) === true) {
				$notified++;
			}
		}

		return $notified;
	}//end notifyMention()

	/**
	 * Ring one mentioned user's bell and put the mention in their queue.
	 *
	 * The bell rings once; the queue waits. A notification a person clears is
	 * gone, and the thing they were called into is still theirs to deal with,
	 * so the mention is also written down (one-personal-queue, D-2).
	 *
	 * @param NoteMention $mention   The author and the note.
	 * @param string      $recipient The mentioned user.
	 *
	 * @return bool True when the notification went out and the mention was queued.
	 */
	private function notifyOne(NoteMention $mention, string $recipient): bool {
		$note = $mention->note;
		try {
			$this->notificationManager->notify($this->notificationFor(mention: $mention, recipient: $recipient));

			$this->queueRecords->record(
				person: $recipient,
				actor: $mention->writer(),
				subjectType: $note->objectType(),
				subjectId: $note->objectId,
				noteId: $note->noteId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to send note mention notification',
				[
					'mentionedUserId' => $recipient,
					'objectId' => $note->objectId,
					'exception' => $e->getMessage(),
				]
			);

			return false;
		}//end try

		return true;
	}//end notifyOne()

	/**
	 * The bell notification for one mentioned user.
	 *
	 * @param NoteMention $mention   The author and the note.
	 * @param string      $recipient The mentioned user.
	 *
	 * @return INotification The notification, ready to send.
	 */
	private function notificationFor(NoteMention $mention, string $recipient): INotification {
		$note = $mention->note;
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($recipient)
			->setDateTime(new DateTime())
			->setObject($note->objectType(), $note->objectId)
			->setSubject(
				'note_mention',
				[
					'actorUserId' => $mention->actorUserId,
					'actorDisplayName' => $mention->actorDisplayName,
					'register' => $note->register,
					'schema' => $note->schema,
					'objectId' => $note->objectId,
					'noteId' => $note->noteId,
				]
			);

		return $notification;
	}//end notificationFor()
}//end class
