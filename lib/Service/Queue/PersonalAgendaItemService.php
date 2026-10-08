<?php

/**
 * A planned item on your own day, with no case behind it.
 *
 * 🔴 IT IS A CALENDAR EVENT, NOT A CASE WITH NO CASE TYPE. The tempting build
 * is a case: dossiq already has lists, a detail page and a queue for those. It
 * is also the wrong one. A caseless case enters the open-case count, the case
 * list, every report that groups by case type and every deadline sweep, and no
 * amount of filtering afterwards puts it back. So this class depends on the
 * calendar and on NOTHING in the register, and a test asserts exactly that:
 * with no register dependency, a planned item CANNOT reach a case count.
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

use DateTimeImmutable;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Plans and reads a person's own agenda items.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class PersonalAgendaItemService {
	/**
	 * The category that marks an event as one of ours.
	 *
	 * It is what the queue searches for, so an event a person made in the
	 * Calendar app themselves stays theirs and out of the queue.
	 *
	 * @var string
	 */
	public const CATEGORY = 'DOSSIQ-PLANNED';

	/**
	 * The templates a planned item can be made from, and how long each runs.
	 *
	 * A template is a starting point, not a type: the title is editable and
	 * nothing downstream branches on which one was used.
	 *
	 * @var array<string, int>
	 */
	public const TEMPLATES = [
		'call-back' => 15,
		'prepare-decision' => 60,
		'site-visit' => 120,
		'catch-up' => 30,
	];

	/**
	 * How many days ahead the queue looks for planned items.
	 *
	 * @var int
	 */
	private const HORIZON_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param ICalendarManager $calendars The calendar the item is written to.
	 * @param IUserManager     $users     Resolves the person's principal.
	 * @param IL10N            $l10n      Translations.
	 * @param LoggerInterface  $logger    Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly ICalendarManager $calendars,
		private readonly IUserManager $users,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * How long one template runs, in minutes.
	 *
	 * @param string $template The template id.
	 *
	 * @return integer The minutes, 30 for a template nobody declared.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function minutesFor(string $template): int {
		return (int)(self::TEMPLATES[$template] ?? 30);
	}//end minutesFor()

	/**
	 * Plan an item on this person's own calendar.
	 *
	 * @param string $userId   The person.
	 * @param string $title    What the item is.
	 * @param string $startsAt When it starts, any format DateTimeImmutable reads.
	 * @param string $template Which template it came from, or an empty string.
	 *
	 * @return array{uid: string, startsAt: string, minutes: int} What was written.
	 *
	 * @throws RuntimeException When the person has no writable calendar, or the moment is unreadable.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function plan(string $userId, string $title, string $startsAt, string $template = ''): array {
		$title = trim($title);
		if ($title === '') {
			throw new RuntimeException($this->l10n->t('A planned item needs a title.'));
		}

		try {
			$start = new DateTimeImmutable($startsAt);
		} catch (Throwable $e) {
			throw new RuntimeException($this->l10n->t('That is not a moment this calendar can read.'), 0, $e);
		}

		$minutes = $this->minutesFor(template: $template);
		$builder = $this->calendars->createEventBuilder();
		$builder->setSummary($title);
		$builder->setStartDate($start);
		$builder->setEndDate($start->setTimestamp($start->getTimestamp() + ($minutes * 60)));
		$builder->setDescription($this->l10n->t('Planned from your queue. It is not attached to a case.'));
		$ics = $builder->toIcs();

		$ics = str_replace("BEGIN:VEVENT\r\n", "BEGIN:VEVENT\r\nCATEGORIES:" . self::CATEGORY . "\r\n", $ics);
		$uid = $this->uidOf(ics: $ics);
		if ($uid === '') {
			throw new RuntimeException($this->l10n->t('The planned item could not be written.'));
		}

		if ($this->write(userId: $userId, uid: $uid, ics: $ics) === false) {
			throw new RuntimeException($this->l10n->t('You have no calendar this item can be written to.'));
		}

		return ['uid' => $uid, 'startsAt' => $start->format('Y-m-d\TH:i:sP'), 'minutes' => $minutes];
	}//end plan()

	/**
	 * The planned items on this person's calendar in the next fortnight.
	 *
	 * @param string                 $userId The person.
	 * @param DateTimeImmutable|null $now    The moment to look from.
	 *
	 * @return array<int, array{uid: string, title: string, startsAt: string}> The items.
	 *
	 * @throws RuntimeException When the calendars cannot be read.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function upcomingFor(string $userId, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$until = $now->modify('+' . self::HORIZON_DAYS . ' days');

		$found = [];
		foreach ($this->calendarsOf(userId: $userId) as $calendar) {
			try {
				$rows = $calendar->search(
					self::CATEGORY,
					['CATEGORIES'],
					['timerange' => ['start' => $now, 'end' => $until]],
					100
				);
			} catch (Throwable $e) {
				throw new RuntimeException($e->getMessage(), 0, $e);
			}

			foreach ($rows as $row) {
				$item = $this->itemOf(row: $row);
				if ($item !== null) {
					$found[$item['uid']] = $item;
				}
			}
		}

		return array_values($found);
	}//end upcomingFor()

	/**
	 * One search row as a planned item.
	 *
	 * @param array<string, mixed> $row The row the calendar answered.
	 *
	 * @return array{uid: string, title: string, startsAt: string}|null The item, or null when it is unreadable.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function itemOf(array $row): ?array {
		$uid = trim((string)($row['UID'][0] ?? ($row['uid'] ?? '')));
		if ($uid === '') {
			return null;
		}

		$start = ($row['objects'][0]['DTSTART'][0] ?? null);
		$startsAt = '';
		if ($start instanceof \DateTimeInterface) {
			$startsAt = $start->format('Y-m-d\TH:i:sP');
		}

		return [
			'uid' => $uid,
			'title' => trim((string)($row['objects'][0]['SUMMARY'][0] ?? ($row['summary'] ?? $uid))),
			'startsAt' => $startsAt,
		];
	}//end itemOf()

	/**
	 * The person's own calendars.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, \OCP\Calendar\ICalendar> The calendars.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function calendarsOf(string $userId): array {
		if ($this->users->get($userId) === null) {
			return [];
		}

		return $this->calendars->getCalendarsForPrincipal('principals/users/' . $userId);
	}//end calendarsOf()

	/**
	 * Write the event into the first calendar that will take it.
	 *
	 * @param string $userId The person.
	 * @param string $uid    The event uid.
	 * @param string $ics    The event.
	 *
	 * @return bool TRUE when it was written.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function write(string $userId, string $uid, string $ics): bool {
		foreach ($this->calendarsOf(userId: $userId) as $calendar) {
			if (($calendar instanceof ICreateFromString) === false) {
				continue;
			}

			if (($calendar instanceof ICalendarIsWritable) === true && $calendar->isWritable() === false) {
				continue;
			}

			try {
				$calendar->createFromString($uid . '.ics', $ics);

				return true;
			} catch (Throwable $e) {
				$this->logger->warning('Dossiq: a planned item could not be written: ' . $e->getMessage());
			}
		}

		return false;
	}//end write()

	/**
	 * The uid the builder put in the ICS.
	 *
	 * @param string $ics The event.
	 *
	 * @return string The uid, or an empty string.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function uidOf(string $ics): string {
		if (preg_match('/^UID:(.+)$/m', $ics, $matches) !== 1) {
			return '';
		}

		return trim($matches[1]);
	}//end uidOf()
}//end class
