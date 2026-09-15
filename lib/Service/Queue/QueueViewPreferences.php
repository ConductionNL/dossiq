<?php

/**
 * What a person may change about their own queue.
 *
 * They may order it, group it, and hide a group for today. They may not make
 * an item disappear while the work still stands, and that is the point of
 * keeping this class small: everything a reader can do to the queue lives
 * here, so the list of gestures that CANNOT remove work is readable in one
 * screen rather than inferred from the absence of an endpoint.
 *
 * "For today" is stored as the date it was hidden on, not as a flag. A flag
 * would still be set tomorrow morning, which is how a to-do list becomes a
 * place people forget to visit.
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

use OCA\Dossiq\AppInfo\Application;
use InvalidArgumentException;
use OCP\Config\Exceptions\TypeConflictException;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * The reader's own view settings for their queue.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class QueueViewPreferences {
	/**
	 * The preference holding the groups hidden for a day, as JSON.
	 *
	 * @var string
	 */
	public const PREF_HIDDEN_GROUPS = 'queue_hidden_groups';

	/**
	 * The preference holding what the queue is grouped by.
	 *
	 * @var string
	 */
	public const PREF_GROUP_BY = 'queue_group_by';

	/**
	 * What the queue can be grouped by. `source` is the default because it is
	 * the grouping that answers "who is asking me".
	 *
	 * @var array<int, string>
	 */
	public const GROUPINGS = ['source', 'priority', 'due'];

	/**
	 * Constructor.
	 *
	 * @param IUserConfig     $userConfig Per-user preferences.
	 * @param LoggerInterface $logger     Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What this person groups their queue by.
	 *
	 * @param string $userId The reader.
	 *
	 * @return string One of GROUPINGS.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function groupBy(string $userId): string {
		try {
			$stored = $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_GROUP_BY, 'source');
		} catch (InvalidArgumentException | TypeConflictException $e) {
			$this->logger->warning('Dossiq: the queue grouping could not be read: ' . $e->getMessage());

			return 'source';
		}

		if (in_array($stored, self::GROUPINGS, true) === false) {
			return 'source';
		}

		return $stored;
	}//end groupBy()

	/**
	 * Remember what this person groups their queue by.
	 *
	 * @param string $userId  The reader.
	 * @param string $groupBy One of GROUPINGS.
	 *
	 * @return string The grouping now stored.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function setGroupBy(string $userId, string $groupBy): string {
		if (in_array($groupBy, self::GROUPINGS, true) === false) {
			$groupBy = 'source';
		}

		$this->userConfig->setValueString($userId, Application::APP_ID, self::PREF_GROUP_BY, $groupBy);

		return $groupBy;
	}//end setGroupBy()

	/**
	 * The groups this person has hidden, and is still hiding today.
	 *
	 * @param string $userId The reader.
	 * @param string $today  The reader's today, `Y-m-d`.
	 *
	 * @return array<int, string> The hidden group keys.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function hiddenGroups(string $userId, string $today): array {
		$hidden = [];
		foreach ($this->storedHidden(userId: $userId) as $group => $day) {
			if ($day === $today) {
				$hidden[] = (string)$group;
			}
		}

		return $hidden;
	}//end hiddenGroups()

	/**
	 * Hide one group until tomorrow.
	 *
	 * @param string $userId The reader.
	 * @param string $group  The group key to hide.
	 * @param string $today  The reader's today, `Y-m-d`.
	 *
	 * @return array<int, string> The groups hidden after this call.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function hideForToday(string $userId, string $group, string $today): array {
		$stored = $this->storedHidden(userId: $userId);
		$stored[$group] = $today;

		// Yesterday's entries are dropped on write rather than on read, so the
		// preference cannot grow one key per group per day for ever.
		$stored = array_filter($stored, static fn (string $day): bool => ($day === $today));

		$this->userConfig->setValueString(
			$userId,
			Application::APP_ID,
			self::PREF_HIDDEN_GROUPS,
			json_encode($stored, JSON_THROW_ON_ERROR)
		);

		return array_keys($stored);
	}//end hideForToday()

	/**
	 * Show a group again.
	 *
	 * @param string $userId The reader.
	 * @param string $group  The group key to show.
	 * @param string $today  The reader's today, `Y-m-d`.
	 *
	 * @return array<int, string> The groups hidden after this call.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function showAgain(string $userId, string $group, string $today): array {
		$stored = $this->storedHidden(userId: $userId);
		unset($stored[$group]);
		$stored = array_filter($stored, static fn (string $day): bool => ($day === $today));

		$this->userConfig->setValueString(
			$userId,
			Application::APP_ID,
			self::PREF_HIDDEN_GROUPS,
			json_encode($stored, JSON_THROW_ON_ERROR)
		);

		return array_keys($stored);
	}//end showAgain()

	/**
	 * The stored hide-for-today map, group key to the date it was hidden on.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array<string, string> The map.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function storedHidden(string $userId): array {
		try {
			$raw = $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_HIDDEN_GROUPS, '');
		} catch (InvalidArgumentException | TypeConflictException $e) {
			// Narrow on purpose: see the note in PersonalStageService. A
			// reader whose preference cannot be read sees every group, which
			// is the safe direction for a queue to be wrong in.
			$this->logger->warning('Dossiq: the hidden queue groups could not be read: ' . $e->getMessage());
			$raw = '';
		}

		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $group => $day) {
			if (is_string($group) === true && is_string($day) === true) {
				$map[$group] = $day;
			}
		}

		return $map;
	}//end storedHidden()
}//end class
