<?php

/**
 * The flow document a planned follow-up is written as, and read back from.
 *
 * Pure: it takes strings and dates and answers arrays, touches no store and
 * resolves no service. That is deliberate, and it is what makes the rules that
 * matter testable without an instance — the schedule trigger carries an
 * explicit `runAs`, the cron fields say when the follow-up comes round, and the
 * series says when it stops coming round.
 *
 * 🔴 FIVE CRON FIELDS CANNOT SAY "ONCE". Minute, hour, day and month name one
 * minute of one day of one month, and that minute comes round again next year.
 * A follow-up with no recurrence keeps its promise through
 * {@see \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob}, which switches the
 * flow off once it has fired. Nothing here pretends otherwise.
 *
 * 🔴 A DAY-OF-MONTH ABOVE 28 SKIPS MONTHS, SILENTLY. `0 6 31 * *` names the
 * 31st, and February, April, June, September and November do not have one, so a
 * monthly series anchored on the 31st would quietly miss five occurrences a
 * year. So when the anchor day does not exist in every month the series can
 * fire in, the day field becomes `L` — the last day of the month, which
 * `dragonmantank/cron-expression` (the parser OpenRegister's scheduler uses)
 * reads — and 31 August plus one month is 30 September rather than 1 October.
 * {@see self::nextOccurrence()} answers the same dates the cron fires on, so
 * the Related tab and the scheduler never disagree.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use RuntimeException;

/**
 * Builds and reads the flow behind one planned follow-up case, or one series.
 *
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
class PlannedFollowUpDocument {

	/**
	 * The `applicationSlug` every planned follow-up flow carries.
	 *
	 * It is the marker that separates the flows this app WRITES from the flows
	 * it SHIPS (which carry none), and `FlowMapper::findAllFlows()` filters on
	 * it server-side. Recognising a planned flow by its NAME instead would
	 * break the moment somebody renamed one in the flow editor.
	 *
	 * @var string
	 */
	public const PLANNED_SLUG = 'dossiq-planned-follow-up';

	/**
	 * The `handoffSource` prefix every case a series creates carries.
	 *
	 * Followed by the series flow's uuid, so the cases one series has opened
	 * are one query rather than a join nobody can write.
	 *
	 * @var string
	 */
	public const SERIES_SOURCE = 'planned-series:';

	/**
	 * A follow-up that happens once. Today's behaviour.
	 *
	 * @var string
	 */
	public const RECURRENCE_NONE = 'none';

	/**
	 * The recurrences a follow-up may carry, and how many months apart they are.
	 *
	 * @var array<string, int>
	 */
	public const RECURRENCE_MONTHS = [
		self::RECURRENCE_NONE => 0,
		'monthly' => 1,
		'quarterly' => 3,
		'halfYearly' => 6,
		'yearly' => 12,
	];

	/**
	 * Days in each month of a NON-leap year, indexed 1 to 12.
	 *
	 * Non-leap on purpose: it answers "is there a 29th in every February this
	 * series can fire in", and three years in four there is not.
	 *
	 * @var array<int, int>
	 */
	private const MONTH_LENGTHS = [
		1 => 31,
		2 => 28,
		3 => 31,
		4 => 30,
		5 => 31,
		6 => 30,
		7 => 31,
		8 => 31,
		9 => 30,
		10 => 31,
		11 => 30,
		12 => 31,
	];

	/**
	 * The hour a planned follow-up is created on its date.
	 *
	 * @var integer
	 */
	private const HOUR = 6;

	/**
	 * How many occurrences past the requested moment are considered.
	 *
	 * The candidate index is computed rather than counted up to, so four steps
	 * is already more than the rounding can be out by. The ceiling exists so a
	 * malformed anchor can never loop.
	 *
	 * @var integer
	 */
	private const LOOKAHEAD = 4;

	/**
	 * The flow document for one planned follow-up, repeating or not.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param DateTimeImmutable $due The date the first occurrence is due.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as.
	 * @param string $recurrence One of {@see self::RECURRENCE_MONTHS}.
	 * @param string $until The last date the series may fire on, as `Y-m-d`, or the empty string.
	 * @param integer $count How many occurrences the series runs for, or 0 for no count.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function build(
		string $caseId,
		string $caseTypeId,
		DateTimeImmutable $due,
		string $title,
		string $uid,
		string $recurrence = self::RECURRENCE_NONE,
		string $until = '',
		int $count = 0,
	): array {
		$cron = $this->cronFor(due: $due, recurrence: $recurrence);
		$series = [
			'recurrence' => $recurrence,
			'anchor' => $due->format('Y-m-d'),
			'until' => $until,
			'count' => $count,
		];

		return [
			'name' => 'Planned follow-up: ' . $title,
			'description' => $this->describe(
				caseId: $caseId,
				caseTypeId: $caseTypeId,
				due: $due,
				uid: $uid,
				recurrence: $recurrence
			),
			'app' => Application::APP_ID,
			'applicationSlug' => self::PLANNED_SLUG,
			'trigger' => 'schedule',
			'cron' => $cron,
			'executionMode' => 'async',
			'enabled' => false,
			'nodes' => [
				[
					'id' => 'when',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => $cron, 'runAs' => $uid],
				],
				[
					'id' => 'create',
					'type' => 'dossiq.createSubCase',
					'config' => [
						'caseType' => $caseTypeId,
						'title' => $title,
						'relatedCases' => [$caseId],
						'series' => $series,
					],
				],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e-create', 'from' => 'when', 'to' => 'create'],
				['id' => 'e-done', 'from' => 'create', 'to' => 'done'],
			],
			'limits' => ['maxTransitions' => 10],
		];
	}//end build()

	/**
	 * Stamp the series flow's own id onto the cases it will create.
	 *
	 * Called once, between the first save and the publish, because the uuid a
	 * series is known by does not exist until the row does — and a published
	 * flow's graph is immutable, so after the publish there is no second
	 * chance.
	 *
	 * @param array<string, mixed> $document The document as built.
	 * @param string $flowId The stored flow's uuid.
	 *
	 * @return array<int, mixed> The nodes, with `handoffSource` on the create node.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function withSeriesSource(array $document, string $flowId): array {
		$nodes = (array)($document['nodes'] ?? []);
		foreach ($nodes as $index => $node) {
			if (is_array($node) === false || ($node['type'] ?? '') !== 'dossiq.createSubCase') {
				continue;
			}

			$config = (array)($node['config'] ?? []);
			$config['handoffSource'] = self::SERIES_SOURCE . $flowId;
			$nodes[$index]['config'] = $config;
		}

		return $nodes;
	}//end withSeriesSource()

	/**
	 * Validate and parse the requested date.
	 *
	 * Parsed WITHOUT `DateTimeImmutable::createFromFormat()`: a static call is
	 * a phpmd `StaticAccess` finding, and the shape check it would do is done
	 * here in full anyway.
	 *
	 * @param string $date The date as `Y-m-d`.
	 *
	 * @return DateTimeImmutable The parsed date.
	 *
	 * @throws RuntimeException `invalid_date` when it is absent or unparseable.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function dueDate(string $date): DateTimeImmutable {
		$trimmed = trim($date);
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $parts) !== 1) {
			throw new RuntimeException('invalid_date');
		}

		if (checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			throw new RuntimeException('invalid_date');
		}

		return new DateTimeImmutable($trimmed);
	}//end dueDate()

	/**
	 * Validate a requested recurrence.
	 *
	 * An unknown token is REFUSED rather than treated as `none`. A series
	 * somebody asked for and did not get is the failure this change exists to
	 * end, and falling back would reintroduce it one typo at a time.
	 *
	 * @param string $recurrence The requested token, or the empty string.
	 *
	 * @return string The recurrence, `none` when nothing was asked for.
	 *
	 * @throws RuntimeException `invalid_recurrence` when the token is not one of ours.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function recurrenceOf(string $recurrence): string {
		$trimmed = trim($recurrence);
		if ($trimmed === '') {
			return self::RECURRENCE_NONE;
		}

		if (array_key_exists($trimmed, self::RECURRENCE_MONTHS) === false) {
			throw new RuntimeException('invalid_recurrence');
		}

		return $trimmed;
	}//end recurrenceOf()

	/**
	 * Validate the end a series was given.
	 *
	 * A series may end on a date, after a number of occurrences, or neither —
	 * an annual permit check that runs until somebody stops it is a real thing
	 * to ask for. Both at once is refused: two ends is two answers to "when
	 * does this stop", and the one that wins would be an implementation detail
	 * nobody can see from the form.
	 *
	 * @param string $recurrence The recurrence the end belongs to.
	 * @param string $until The end date as `Y-m-d`, or the empty string.
	 * @param integer $count The number of occurrences, or 0.
	 *
	 * @return array{until: string, count: int} The end condition.
	 *
	 * @throws RuntimeException `invalid_end` when both or neither shape is well formed.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function endOf(string $recurrence, string $until, int $count): array {
		$date = trim($until);
		if ($recurrence === self::RECURRENCE_NONE) {
			// A single follow-up ends when it has happened. Anything else asked
			// for is dropped rather than refused: the form does not offer it.
			return ['until' => '', 'count' => 0];
		}

		if ($date !== '' && $count > 0) {
			throw new RuntimeException('invalid_end');
		}

		if ($date !== '') {
			// Parsed for its shape; the value stored is the string, because
			// every comparison this class makes is a `Y-m-d` string compare.
			$this->dueDate(date: $date);

			return ['until' => $date, 'count' => 0];
		}

		if ($count < 0) {
			throw new RuntimeException('invalid_end');
		}

		return ['until' => '', 'count' => $count];
	}//end endOf()

	/**
	 * What one planned flow says it will create, read off its own graph.
	 *
	 * The graph is the record: a name can be edited, the node config is what
	 * actually runs. The date comes from the series anchor, or from the
	 * schedule node's cron for a flow written before series existed, rather
	 * than from the description for the same reason.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 * @param DateTimeImmutable|null $from The moment "next" is measured from, defaulting to now.
	 *
	 * @return array{case: string, caseType: string, title: string, date: string, recurrence: string, until: string, count: int}|null The marker, or null when the graph is not one of ours.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function markerOf(array $nodes, ?DateTimeImmutable $from = null): ?array {
		['create' => $create, 'cron' => $cron] = $this->readNodes(nodes: $nodes);
		if ($create === null) {
			return null;
		}

		$related = (array)($create['relatedCases'] ?? []);
		$caseId = trim((string)($related[0] ?? ''));
		if ($caseId === '') {
			return null;
		}

		$series = $this->seriesOf(nodes: $nodes);

		return [
			'case' => $caseId,
			'caseType' => (string)($create['caseType'] ?? ''),
			'title' => (string)($create['title'] ?? ''),
			'date' => $this->nextDate(series: $series, cron: $cron, from: $from),
			'recurrence' => $series['recurrence'],
			'until' => $series['until'],
			'count' => $series['count'],
		];
	}//end markerOf()

	/**
	 * The series a planned flow carries, filled in for a flow that predates them.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return array{recurrence: string, anchor: string, until: string, count: int} The series.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function seriesOf(array $nodes): array {
		['create' => $create] = $this->readNodes(nodes: $nodes);
		$stored = (array)(($create ?? [])['series'] ?? []);

		$recurrence = (string)($stored['recurrence'] ?? self::RECURRENCE_NONE);
		if (array_key_exists($recurrence, self::RECURRENCE_MONTHS) === false) {
			$recurrence = self::RECURRENCE_NONE;
		}

		return [
			'recurrence' => $recurrence,
			'anchor' => (string)($stored['anchor'] ?? ''),
			'until' => (string)($stored['until'] ?? ''),
			'count' => (int)($stored['count'] ?? 0),
		];
	}//end seriesOf()

	/**
	 * The next date after `$from` that this series fires on.
	 *
	 * 🔑 CALENDAR ARITHMETIC, NOT `+1 month`. PHP's relative month overflows:
	 * 31 August plus one month is 1 October, which is the bug the competitor
	 * corpus found in two products. Each occurrence is computed from the
	 * ANCHOR rather than from the previous occurrence, so a month that clamps
	 * cannot drag the rest of the series earlier with it, and a day that does
	 * not exist in the target month becomes that month's last day.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 * @param DateTimeImmutable $from The moment to look forward from, exclusive.
	 *
	 * @return DateTimeImmutable|null The next occurrence, or null when there is none.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function nextOccurrence(string $recurrence, DateTimeImmutable $anchor, DateTimeImmutable $from): ?DateTimeImmutable {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		$after = $from->format('Y-m-d');
		if ($step === 0) {
			if ($anchor->format('Y-m-d') > $after) {
				return $anchor;
			}

			return null;
		}

		$anchorMonths = (((int)$anchor->format('Y') * 12) + (int)$anchor->format('n'));
		$fromMonths = (((int)$from->format('Y') * 12) + (int)$from->format('n'));
		$first = max(0, intdiv(($fromMonths - $anchorMonths), $step));

		for ($index = $first; $index <= ($first + self::LOOKAHEAD); $index++) {
			$candidate = $this->occurrence(recurrence: $recurrence, anchor: $anchor, index: $index);
			if ($candidate->format('Y-m-d') > $after) {
				return $candidate;
			}
		}

		return null;
	}//end nextOccurrence()

	/**
	 * Whether a series has nothing left to do and the flow may be switched off.
	 *
	 * A document with recurrence `none` is spent the moment it has fired, which
	 * is exactly the rule the sweep enforced before series existed. A series
	 * with neither an end date nor a count is never spent, and stops only when
	 * somebody stops it.
	 *
	 * @param array<string, mixed> $document The flow document, or a stored flow's `{nodes: [...]}`.
	 * @param integer $firedCount How many occurrences have been created.
	 * @param DateTimeImmutable $today The day the sweep is running on.
	 *
	 * @return boolean Whether the flow may be switched off.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function isSpent(array $document, int $firedCount, DateTimeImmutable $today): bool {
		$series = $this->seriesOf(nodes: (array)($document['nodes'] ?? []));
		if ($series['recurrence'] === self::RECURRENCE_NONE) {
			return $firedCount >= 1;
		}

		if ($series['count'] > 0) {
			return $firedCount >= $series['count'];
		}

		if ($series['until'] === '') {
			return false;
		}

		if ($series['anchor'] === '') {
			// An anchorless series cannot be stepped, so the end date alone
			// decides. Reachable only for a hand-edited flow.
			return $today->format('Y-m-d') > $series['until'];
		}

		$next = $this->nextOccurrence(
			recurrence: $series['recurrence'],
			anchor: new DateTimeImmutable($series['anchor']),
			from: $today
		);

		return $next === null || $next->format('Y-m-d') > $series['until'];
	}//end isSpent()

	/**
	 * The occurrence at a given step from the anchor.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 * @param integer $index How many steps past the anchor.
	 *
	 * @return DateTimeImmutable The occurrence.
	 */
	private function occurrence(string $recurrence, DateTimeImmutable $anchor, int $index): DateTimeImmutable {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		$ahead = (((int)$anchor->format('n') - 1) + ($index * $step));
		$year = ((int)$anchor->format('Y') + intdiv($ahead, 12));
		$month = (($ahead % 12) + 1);

		$length = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
		$day = min((int)$anchor->format('j'), $length);
		if ($this->usesLastDay(recurrence: $recurrence, anchor: $anchor) === true) {
			$day = $length;
		}

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
	}//end occurrence()

	/**
	 * Whether this series runs on the last day of its months rather than a fixed one.
	 *
	 * True exactly when the anchor day does not exist in every month the series
	 * can fire in — a monthly series on the 31st, a quarterly one that passes
	 * through February on the 30th, a yearly one on 29 February. The cron day
	 * field is `L` in that case, and the arithmetic here answers the same days.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 *
	 * @return boolean Whether the last day of the month is used.
	 */
	private function usesLastDay(string $recurrence, DateTimeImmutable $anchor): bool {
		$shortest = 31;
		foreach ($this->monthsOf(recurrence: $recurrence, anchor: $anchor) as $month) {
			$shortest = min($shortest, (int)self::MONTH_LENGTHS[$month]);
		}

		return (int)$anchor->format('j') > $shortest;
	}//end usesLastDay()

	/**
	 * The calendar months a recurrence fires in.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 *
	 * @return array<int, int> The month numbers, ascending.
	 */
	private function monthsOf(string $recurrence, DateTimeImmutable $anchor): array {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		if ($step === 0 || $step === 1) {
			return range(1, 12);
		}

		$months = [];
		$start = (int)$anchor->format('n');
		for ($offset = 0; $offset < 12; $offset += $step) {
			$months[] = ((($start - 1 + $offset) % 12) + 1);
		}

		sort($months);

		return $months;
	}//end monthsOf()

	/**
	 * The create-node config and the schedule cron, from one node list.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return array{create: array<string, mixed>|null, cron: string} What was found.
	 */
	private function readNodes(array $nodes): array {
		$create = null;
		$cron = '';
		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$type = (string)($node['type'] ?? '');
			$config = (array)($node['config'] ?? []);
			if ($type === 'dossiq.createSubCase') {
				$create = $config;
			}

			if ($type === 'openregister.trigger-schedule') {
				$cron = (string)($config['cron'] ?? '');
			}
		}

		return ['create' => $create, 'cron' => $cron];
	}//end readNodes()

	/**
	 * The cron expression for one date and one recurrence.
	 *
	 * Minute and hour are always pinned. The weekday field stays open, because
	 * pinning it too would AND two calendar constraints that disagree in most
	 * years and the flow would fire on neither.
	 *
	 * @param DateTimeImmutable $due The first occurrence.
	 * @param string $recurrence The recurrence token.
	 *
	 * @return string The five-field expression.
	 */
	private function cronFor(DateTimeImmutable $due, string $recurrence): string {
		if ($recurrence === self::RECURRENCE_NONE) {
			return sprintf('0 %d %d %d *', self::HOUR, (int)$due->format('j'), (int)$due->format('n'));
		}

		$day = (string)(int)$due->format('j');
		if ($this->usesLastDay(recurrence: $recurrence, anchor: $due) === true) {
			$day = 'L';
		}

		$months = '*';
		if ($recurrence !== 'monthly') {
			$months = implode(',', $this->monthsOf(recurrence: $recurrence, anchor: $due));
		}

		return sprintf('0 %d %s %s *', self::HOUR, $day, $months);
	}//end cronFor()

	/**
	 * The sentence a planned flow carries as its description.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param DateTimeImmutable $due The first occurrence.
	 * @param string $uid The user who planned it.
	 * @param string $recurrence The recurrence token.
	 *
	 * @return string The description.
	 */
	private function describe(
		string $caseId,
		string $caseTypeId,
		DateTimeImmutable $due,
		string $uid,
		string $recurrence,
	): string {
		if ($recurrence === self::RECURRENCE_NONE) {
			return sprintf(
				'Creates a case of type %s on %s, related to case %s. Planned by %s.',
				$caseTypeId,
				$due->format('Y-m-d'),
				$caseId,
				$uid
			);
		}

		return sprintf(
			'Creates a case of type %s every %s from %s, related to case %s. Planned by %s.',
			$caseTypeId,
			$this->inWords(recurrence: $recurrence),
			$due->format('Y-m-d'),
			$caseId,
			$uid
		);
	}//end describe()

	/**
	 * A recurrence as an English interval, for the flow's own description.
	 *
	 * Not translated: a flow description is stored once, read in the flow
	 * editor and shared by every reader of the instance, so it follows the rest
	 * of the document rather than the session locale.
	 *
	 * @param string $recurrence The recurrence token.
	 *
	 * @return string The interval.
	 */
	private function inWords(string $recurrence): string {
		$words = [
			'monthly' => 'month',
			'quarterly' => 'quarter',
			'halfYearly' => 'half year',
			'yearly' => 'year',
		];

		return (string)($words[$recurrence] ?? 'occurrence');
	}//end inWords()

	/**
	 * The next date to show for a planned flow.
	 *
	 * @param array{recurrence: string, anchor: string, until: string, count: int} $series The series.
	 * @param string $cron The schedule node's cron, for a flow written before series existed.
	 * @param DateTimeImmutable|null $from The moment to measure from.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 */
	private function nextDate(array $series, string $cron, ?DateTimeImmutable $from): string {
		if ($series['anchor'] === '') {
			return $this->dateOfCron(cron: $cron);
		}

		$moment = $from;
		if ($moment === null) {
			$moment = new DateTimeImmutable('today');
		}

		if ($series['recurrence'] === self::RECURRENCE_NONE) {
			return $series['anchor'];
		}

		$next = $this->nextOccurrence(
			recurrence: $series['recurrence'],
			anchor: new DateTimeImmutable($series['anchor']),
			from: $moment
		);
		if ($next === null) {
			return '';
		}

		return $next->format('Y-m-d');
	}//end nextDate()

	/**
	 * The next calendar date a pinned cron expression names.
	 *
	 * Only reached for a flow written before a planned follow-up carried a
	 * series, which is why it reads two numeric fields and gives up on anything
	 * else: those flows are all single follow-ups with a pinned day and month.
	 *
	 * @param string $cron The five-field expression.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 */
	private function dateOfCron(string $cron): string {
		$fields = preg_split('/\s+/', trim($cron));
		if (is_array($fields) === false || count($fields) !== 5) {
			return '';
		}

		$day = (int)$fields[2];
		$month = (int)$fields[3];
		if ($day < 1 || $month < 1) {
			return '';
		}

		$year = (int)date('Y');
		$candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
		if ($candidate >= date('Y-m-d')) {
			return $candidate;
		}

		return sprintf('%04d-%02d-%02d', ($year + 1), $month, $day);
	}//end dateOfCron()
}//end class
