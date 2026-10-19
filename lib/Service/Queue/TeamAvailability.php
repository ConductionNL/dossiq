<?php

/**
 * Where each handler on the team queue's "Team today" card is today
 *
 * Reads the Nextcloud user status of every handler and turns it into one line
 * ("Available", "In a meeting until 14:00", "Absent today") and whether the
 * person is present, so the card can count "4 of 5 present".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;

/**
 * Adds each handler's availability line and presence from the Nextcloud user status.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class TeamAvailability {
	/**
	 * Constructor.
	 *
	 * @param IUserStatusManager $userStatusManager Reads the Nextcloud user status.
	 * @param IL10N              $l10n              Translates the availability lines.
	 * @param IDateTimeFormatter $dateTimeFormatter Formats the time a status message ends.
	 *
	 * @return void
	 */
	public function __construct(
		private IUserStatusManager $userStatusManager,
		private IL10N $l10n,
		private IDateTimeFormatter $dateTimeFormatter,
	) {
	}//end __construct()

	/**
	 * Add `availability` and `present` to each handler row.
	 *
	 * A handler without a status reads as absent today.
	 *
	 * @param array<int, array<string, mixed>> $handlers Rows with a `handler` user id.
	 *
	 * @return array<int, array<string, mixed>> The rows with `availability` and `present`.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function annotate(array $handlers): array {
		$userIds  = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['handler'] ?? ''), $handlers)));
		$statuses = [];
		if ($userIds !== []) {
			$statuses = $this->userStatusManager->getUserStatuses($userIds);
		}

		foreach ($handlers as $index => $row) {
			$status = ($statuses[(string) ($row['handler'] ?? '')] ?? null);
			$handlers[$index]['availability'] = $this->lineOf(status: $status);
			$handlers[$index]['present']      = $this->isPresent(status: $status);
		}

		return $handlers;
	}//end annotate()

	/**
	 * The availability line: the person's own status message, with the time it
	 * ends when it has one, else a word for the status.
	 *
	 * @param IUserStatus|null $status The user status, or null without one.
	 *
	 * @return string The line.
	 */
	private function lineOf(?IUserStatus $status): string {
		if ($status === null) {
			return $this->l10n->t('Absent today');
		}

		$message = trim((string) $status->getMessage());
		if ($message !== '') {
			$clearAt = $status->getClearAt();
			if ($clearAt === null) {
				return $message;
			}

			return $this->l10n->t('%1$s until %2$s', [$message, $this->dateTimeFormatter->formatTime($clearAt->getTimestamp(), 'short')]);
		}

		return match ($status->getStatus()) {
			IUserStatus::ONLINE => $this->l10n->t('Available'),
			IUserStatus::AWAY => $this->l10n->t('Away'),
			IUserStatus::DND, IUserStatus::BUSY => $this->l10n->t('Busy'),
			default => $this->l10n->t('Absent today'),
		};
	}//end lineOf()

	/**
	 * Whether the person is at work today: any status but offline or invisible.
	 *
	 * @param IUserStatus|null $status The user status, or null without one.
	 *
	 * @return bool True when present.
	 */
	private function isPresent(?IUserStatus $status): bool {
		if ($status === null) {
			return false;
		}

		return in_array($status->getStatus(), [IUserStatus::OFFLINE, IUserStatus::INVISIBLE], true) === false;
	}//end isPresent()
}//end class
