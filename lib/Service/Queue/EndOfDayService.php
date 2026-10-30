<?php

/**
 * Dossiq end of day
 *
 * The lists of the "Close out your day" page, worked out on the server so they
 * survive a reload: what the reader opened today and has no note on yet, the
 * notes the reader recorded today, and what comes first tomorrow.
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
 * @spec openspec/specs/end-of-day-manifest/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Works out the reader's day from the queue, OpenRegister's read state and the notes.
 *
 * "Opened today" is OpenRegister's per-reader read state, never the object's
 * own updated time, which would list a colleague's afternoon as the reader's.
 * "Recorded today" is the reader's own notes of today on those items, so the
 * list is there again after a reload.
 *
 * @spec openspec/specs/end-of-day-manifest/spec.md
 */
class EndOfDayService {

	private const READ_STATE = 'OCA\\OpenRegister\\Service\\Interaction\\ReadStateService';

	/**
	 * The read states by object uuid: a task is no register object, so its
	 * read state is asked for by its engine uuid.
	 */
	private const READ_STATES = 'OCA\\OpenRegister\\Db\\ObjectReadStateMapper';

	private const NOTES = 'OCA\\OpenRegister\\Service\\NoteService';

	/**
	 * How many items "First thing tomorrow" lists.
	 */
	private const TOMORROW_LIMIT = 2;

	/**
	 * Constructor.
	 *
	 * @param PersonalQueueService $queue    The reader's queue, the candidates.
	 * @param SettingsService      $settings The app's OpenRegister configuration.
	 * @param IL10N                $l10n     The labels on screen.
	 * @param LoggerInterface      $logger   The log.
	 * @param EngineTaskGateway    $tasks    The task engine, where tasks live.
	 * @param EndOfDayRowMeta      $rowMeta  The case a row names under its title.
	 */
	public function __construct(
		private readonly PersonalQueueService $queue,
		private readonly SettingsService $settings,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly EngineTaskGateway $tasks,
		private readonly EndOfDayRowMeta $rowMeta,
	) {
	}//end __construct()

	/**
	 * The reader's day.
	 *
	 * @param string                 $userId The reader.
	 * @param DateTimeImmutable|null $now    Now, in the reader's time zone; the server's now when absent.
	 *
	 * @return array<string, mixed> `open`, `recorded`, `tomorrow`, `figures`, the counts and what could not be read.
	 *
	 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
	 */
	public function dayFor(string $userId, ?DateTimeImmutable $now=null): array {
		$now = ($now ?? new DateTimeImmutable());
		$today = $now->format('Y-m-d');
		$queue = $this->queue->forPerson(userId: $userId, now: $now);
		$candidates = array_values(
			array_filter(
				$queue['items'],
				static fn (array $item): bool => in_array(($item['subjectType'] ?? ''), ['case', 'task'], true)
			)
		);

		$open = [];
		$recorded = [];
		$untouched = [];
		foreach ($candidates as $item) {
			$row = $this->rowOf(item: $item);
			$note = $this->noteOfToday(item: $item, userId: $userId, today: $today, zone: $now->getTimezone());
			if ($note !== null) {
				$facts = $this->rowMeta->factsOf(item: $item);
				$recorded[] = $row + $note + ['recordedMeta' => $this->rowMeta->recordedLine(facts: $facts, time: $note['recordedTime'])];
				continue;
			}

			if ($this->openedToday(item: $item, userId: $userId, today: $today, zone: $now->getTimezone()) === true) {
				$open[] = $row + ['meta' => $this->rowMeta->openLine(facts: $this->rowMeta->factsOf(item: $item))];
				continue;
			}

			$untouched[] = $row;
		}

		usort($recorded, static fn (array $left, array $right): int => strcmp((string)$right['recordedAt'], (string)$left['recordedAt']));

		$opened = (count($open) + count($recorded));
		$cases = count(array_filter([...$open, ...$recorded], static fn (array $row): bool => $row['subjectType'] === 'case'));

		return [
			'open' => $open,
			'recorded' => $recorded,
			'tomorrow' => $this->tomorrowFirst(rows: $untouched, now: $now),
			'opened' => $opened,
			'figures' => [
				[
					'label' => $this->l10n->t('Opened'),
					'value' => $opened,
					'note' => $this->l10n->t('%1$s cases, %2$s tasks', [$cases, ($opened - $cases)]),
				],
				[
					'label' => $this->l10n->t('Notes recorded'),
					'value' => count($recorded),
					'note' => $this->l10n->t('of the %s', [$opened]),
				],
			],
			'unavailable' => $queue['unavailable'],
			'unavailableText' => $this->unavailableText(unavailable: $queue['unavailable']),
		];
	}//end dayFor()

	/**
	 * A queue item as a row of the page's lists: the fields the record lists
	 * template, the route the title opens and the notes endpoint it records on.
	 *
	 * @param array<string, mixed> $item The queue item.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(array $item): array {
		$type = (string)$item['subjectType'];
		$id = (string)($item['subjectId'] ?? '');
		$route = ($item['route'] ?? []);
		$kind = $this->l10n->t('Case');
		$notesUrl = '/apps/openregister/api/objects/' . rawurlencode($this->register()) . '/'
			. rawurlencode($this->caseSchema()) . '/' . rawurlencode($id) . '/notes';
		if ($type === 'task') {
			$kind = $this->l10n->t('Task');
			$notesUrl = '/apps/openregister/api/flow-tasks/' . rawurlencode($id) . '/notes';
		}

		return [
			'id' => (string)($item['id'] ?? ($type . ':' . $id)),
			'subjectId' => $id,
			'subjectType' => $type,
			'kindLabel' => $kind,
			'title' => (string)($item['title'] ?? ''),
			'dueAt' => ($item['dueAt'] ?? null),
			'routeName' => (string)($route['name'] ?? ''),
			'routeId' => (string)($route['params']['id'] ?? $id),
			'notesUrl' => $notesUrl,
		];
	}//end rowOf()

	/**
	 * Whether OpenRegister's read state says the reader opened this item today.
	 *
	 * @param array<string, mixed> $item   The queue item.
	 * @param string               $userId The reader.
	 * @param string               $today  Today, `Y-m-d` in the reader's zone.
	 * @param DateTimeZone         $zone   The reader's zone.
	 *
	 * @return bool
	 */
	private function openedToday(array $item, string $userId, string $today, DateTimeZone $zone): bool {
		try {
			$state = $this->readStateOf(item: $item, userId: $userId);
		} catch (Throwable $e) {
			// The read state is the register's to answer; an item it cannot
			// answer for has not been opened, as far as anyone can tell.
			$this->logger->debug('Dossiq: no read state for an end-of-day candidate: ' . $e->getMessage());
			return false;
		}

		$seen = '';
		if (is_object($state) === true && method_exists($state, 'jsonSerialize') === true) {
			$seen = (string)($state->jsonSerialize()['lastSeenAt'] ?? '');
		}

		return $this->isToday(moment: $seen, today: $today, zone: $zone);
	}//end openedToday()

	/**
	 * The reader's read state of a queue item, or null: a case's through its
	 * register object, a task's through the task engine by the task's uuid.
	 *
	 * @param array<string, mixed> $item   The queue item.
	 * @param string               $userId The reader.
	 *
	 * @return object|null The read state.
	 *
	 * @spec openspec/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server
	 */
	private function readStateOf(array $item, string $userId): ?object {
		if ($item['subjectType'] === 'task') {
			$task = $this->tasks->find(taskId: (string)$item['subjectId']);
			$states = $this->settings->getOpenRegisterClass(class: self::READ_STATES);
			if ($task === null || $states === null) {
				return null;
			}

			return $states->findOne(userId: $userId, objectUuid: (string)$task['id']);
		}

		$objectService = $this->settings->getObjectService();
		$readState = $this->settings->getOpenRegisterClass(class: self::READ_STATE);
		if ($objectService === null || $readState === null) {
			return null;
		}

		$object = $objectService->find((string)$item['subjectId'], register: $this->register(), schema: $this->caseSchema());
		return $readState->readStateFor(object: $object, userId: $userId);
	}//end readStateOf()

	/**
	 * The reader's latest note of today on this item, as the fields the
	 * "Recorded today" list shows, or null.
	 *
	 * @param array<string, mixed> $item   The queue item.
	 * @param string               $userId The reader.
	 * @param string               $today  Today, `Y-m-d` in the reader's zone.
	 * @param DateTimeZone         $zone   The reader's zone.
	 *
	 * @return array{note: string, recordedAt: string, recordedTime: string}|null
	 */
	private function noteOfToday(array $item, string $userId, string $today, DateTimeZone $zone): ?array {
		$notes = $this->settings->getOpenRegisterClass(class: self::NOTES);
		if ($notes === null) {
			return null;
		}

		$list = [];
		try {
			$list = $notes->getNotesForObject((string)$item['subjectId']);
		} catch (Throwable $e) {
			// The item then reads as not recorded and stays in the open list,
			// where the reader can record it again; it is not dropped.
			$this->logger->warning('Dossiq: the notes of end-of-day candidate ' . (string)$item['id'] . ' could not be read: ' . $e->getMessage());
		}

		$latest = null;
		foreach ((array)$list as $note) {
			$created = (string)($note['createdAt'] ?? '');
			if (($note['actorId'] ?? '') !== $userId || $this->isToday(moment: $created, today: $today, zone: $zone) === false) {
				continue;
			}

			if ($latest === null || strcmp($created, (string)$latest['createdAt']) > 0) {
				$latest = $note;
			}
		}

		if ($latest === null) {
			return null;
		}

		$time = (new DateTimeImmutable((string)$latest['createdAt']))->setTimezone($zone)->format('H:i');
		return ['note' => (string)($latest['message'] ?? ''), 'recordedAt' => (string)$latest['createdAt'], 'recordedTime' => $time];
	}//end noteOfToday()

	/**
	 * The items the reader did not open today whose term ends by tomorrow, soonest first.
	 *
	 * @param list<array<string, mixed>> $rows The untouched rows.
	 * @param DateTimeImmutable          $now  Now.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function tomorrowFirst(array $rows, DateTimeImmutable $now): array {
		$limit = $now->setTime(0, 0)->modify('+1 day')->format('Y-m-d');
		$due = array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => is_string($row['dueAt']) === true && $row['dueAt'] !== '' && substr($row['dueAt'], 0, 10) <= $limit
			)
		);
		usort($due, static fn (array $left, array $right): int => strcmp(substr((string)$left['dueAt'], 0, 10), substr((string)$right['dueAt'], 0, 10)));

		return array_slice($due, 0, self::TOMORROW_LIMIT);
	}//end tomorrowFirst()

	/**
	 * Whether a moment falls on today in the reader's zone.
	 *
	 * @param string       $moment An ISO 8601 moment, or ''.
	 * @param string       $today  Today, `Y-m-d`.
	 * @param DateTimeZone $zone   The reader's zone.
	 *
	 * @return bool
	 */
	private function isToday(string $moment, string $today, DateTimeZone $zone): bool {
		if ($moment === '') {
			return false;
		}

		try {
			return (new DateTimeImmutable($moment))->setTimezone($zone)->format('Y-m-d') === $today;
		} catch (Throwable $e) {
			return false;
		}
	}//end isToday()

	/**
	 * One sentence per queue source that could not be read, '' when all were.
	 *
	 * @param list<array<string, mixed>> $unavailable The sources.
	 *
	 * @return string
	 */
	private function unavailableText(array $unavailable): string {
		$sentences = [];
		foreach ($unavailable as $source) {
			$label = (string)($source['label'] ?? $source['source'] ?? '');
			$sentences[] = $this->l10n->t('%s could not be read, so this list is incomplete.', [$label]);
		}

		return implode(' ', $sentences);
	}//end unavailableText()

	/**
	 * @return string The register the cases live in.
	 */
	private function register(): string {
		return trim((string)$this->settings->getConfigValue('register', 'dossiq'));
	}//end register()

	/**
	 * @return string The case schema.
	 */
	private function caseSchema(): string {
		return trim((string)$this->settings->getConfigValue('case_schema', 'case'));
	}//end caseSchema()
}//end class
