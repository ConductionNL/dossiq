<?php

/**
 * What takes an item off a queue, and what does not.
 *
 * An item leaves when the thing it points at is done, taken over or withdrawn.
 * It never leaves because somebody pressed a button on it. A to-do a person can
 * dismiss is a to-do they will dismiss on a bad morning, and the case behind it
 * still needs doing; the dismissal would then be the only record that anybody
 * ever looked at it, held per person, invisible to the coordinator.
 *
 * What a person may do instead is hide a group until tomorrow. That is the
 * offer this class hands back when a dismissal is refused, so the gesture has
 * somewhere to go rather than simply failing.
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * Decides whether a queue item still stands.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class QueueItemLifecycle {
	/**
	 * The three ways an item leaves, and the only three.
	 *
	 * @var array<int, string>
	 */
	public const CLOSING_REASONS = ['done', 'taken-over', 'withdrawn'];

	/**
	 * What the reader is offered when they try to remove live work.
	 *
	 * @var string
	 */
	public const OFFER_HIDE_GROUP = 'hide-group-for-today';

	/**
	 * Why this item is no longer on the queue, or null while it stands.
	 *
	 * A subject that cannot be found at all is withdrawn: the mechanism that
	 * raised it has removed it, and an item pointing at nothing is the one
	 * kind of stale row nobody can act on.
	 *
	 * @param QueueItem                 $item    The item.
	 * @param array<string, mixed>|null $subject The subject as its mechanism answers it now, or null when it is gone.
	 * @param string                    $userId  The person whose queue this is.
	 *
	 * @return string|null One of CLOSING_REASONS, or null when the item still stands.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closingReason(QueueItem $item, ?array $subject, string $userId): ?string {
		if ($subject === null) {
			return 'withdrawn';
		}

		if ($this->isDone(subject: $subject) === true) {
			return 'done';
		}

		if ($this->isStillOwedBy(item: $item, subject: $subject, userId: $userId) === false) {
			return 'taken-over';
		}

		return null;
	}//end closingReason()

	/**
	 * Whether the item still stands for this person.
	 *
	 * @param QueueItem                 $item    The item.
	 * @param array<string, mixed>|null $subject The subject as its mechanism answers it now.
	 * @param string                    $userId  The person whose queue this is.
	 *
	 * @return bool TRUE while the work is still waiting on them.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function stillStands(QueueItem $item, ?array $subject, string $userId): bool {
		return ($this->closingReason(item: $item, subject: $subject, userId: $userId) === null);
	}//end stillStands()

	/**
	 * What happens when a person tries to remove an item.
	 *
	 * Live work is refused with the offer; work that has already closed needs
	 * no gesture at all, because the next read will not carry it.
	 *
	 * @param QueueItem                 $item    The item.
	 * @param array<string, mixed>|null $subject The subject as its mechanism answers it now.
	 * @param string                    $userId  The person whose queue this is.
	 *
	 * @return array{removed: bool, reason: string|null, offer: string|null} What the queue does.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function onRemoveRequested(QueueItem $item, ?array $subject, string $userId): array {
		$reason = $this->closingReason(item: $item, subject: $subject, userId: $userId);
		if ($reason !== null) {
			return ['removed' => true, 'reason' => $reason, 'offer' => null];
		}

		return ['removed' => false, 'reason' => null, 'offer' => self::OFFER_HIDE_GROUP];
	}//end onRemoveRequested()

	/**
	 * Whether the subject reports itself finished.
	 *
	 * Reads the vocabularies the mechanisms actually use: a case carries
	 * `isFinalStatus`, an engine task carries a terminal `state`, and an
	 * advice or consultation carries a `status` that has left the open set.
	 *
	 * @param array<string, mixed> $subject The subject.
	 *
	 * @return bool TRUE when the subject is finished.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function isDone(array $subject): bool {
		if (($subject['isFinalStatus'] ?? false) === true) {
			return true;
		}

		$state = strtolower(trim((string)($subject['state'] ?? '')));
		if (in_array($state, ['completed', 'terminated', 'disabled'], true) === true) {
			return true;
		}

		$status = strtolower(trim((string)($subject['status'] ?? '')));

		return in_array($status, ['afgerond', 'beantwoord', 'ingetrokken', 'answered', 'withdrawn'], true);
	}//end isDone()

	/**
	 * Whether the subject is still this person's to deal with.
	 *
	 * Covered work is the exception that has to be written down: an item the
	 * reader picked up through a substitution is assigned to the ABSENT
	 * colleague, so the plain assignee comparison would close every covered
	 * item the moment it appeared.
	 *
	 * @param QueueItem            $item    The item.
	 * @param array<string, mixed> $subject The subject.
	 * @param string               $userId  The person whose queue this is.
	 *
	 * @return bool TRUE when the work is still theirs.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function isStillOwedBy(QueueItem $item, array $subject, string $userId): bool {
		$owner = trim((string)($subject['assignee'] ?? ''));
		if ($owner === '') {
			// Nothing names an owner, so nothing says it was taken over. A
			// mention is the everyday case: it names a reader, not an owner.
			return true;
		}

		if ($item->isCovered() === true) {
			return ($owner === $item->coveredFor);
		}

		return ($owner === $userId);
	}//end isStillOwedBy()
}//end class
