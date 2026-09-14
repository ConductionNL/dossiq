<?php

/**
 * Dossiq case priority service.
 *
 * Where a case's priority comes from. Nobody types one: a case stores how
 * much it matters (`impact`) and how soon it matters (`urgency`), and the
 * priority is the answer a matrix gives for that pair. The matrix belongs to
 * the case type, because high impact on a bezwaar and high impact on a
 * melding openbare ruimte are not the same urgency, and an instance default
 * answers for a case type that declares none.
 *
 * Three things can sit on top of the derived answer, in this order:
 *
 *   1. The derived value itself, recomputed on every save.
 *   2. A FLOOR a declared rule raised the case to as its term approached.
 *      A floor only ever lifts, so an extended term cannot make a case less
 *      urgent, and clearing an override returns to the raised answer rather
 *      than to a stale one.
 *   3. A human OVERRIDE, which wins until it is cleared and carries who set
 *      it, when and why.
 *
 * The derived value lands in the `case.priority` field that already existed,
 * so every reader, filter and facet written before this keeps working.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives a case's priority from its impact and its urgency.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
class CasePriorityService {
	/**
	 * How much it matters if this case goes wrong, lowest first.
	 *
	 * @var array<int, string>
	 */
	public const IMPACT_VALUES = ['low', 'medium', 'high'];

	/**
	 * How soon this case matters, lowest first.
	 *
	 * @var array<int, string>
	 */
	public const URGENCY_VALUES = ['low', 'medium', 'high'];

	/**
	 * The priority vocabulary, unchanged from the schema that already had it.
	 *
	 * @var array<int, string>
	 */
	public const PRIORITY_VALUES = ['low', 'normal', 'high', 'urgent'];

	/**
	 * The declared order of the priority values. A list cannot sort on a
	 * word: alphabetically `high` comes before `low` and `urgent` comes last
	 * of all, which is neither the order a handler means nor a usable one.
	 *
	 * @var array<string, int>
	 */
	public const PRIORITY_ORDER = [
		'low' => 1,
		'normal' => 2,
		'high' => 3,
		'urgent' => 4,
	];

	/**
	 * The NL Design System hue each priority is drawn in.
	 *
	 * A name from the palette rather than a hex value, so a themed install
	 * repoints one token and every badge follows. `src/utils/statusColour.js`
	 * resolves these the same way it resolves a status colour.
	 *
	 * @var array<string, string>
	 */
	public const PRIORITY_COLOURS = [
		'low' => 'grey',
		'normal' => 'blue',
		'high' => 'orange',
		'urgent' => 'red',
	];

	/**
	 * What a case takes when neither it nor its case type says otherwise.
	 */
	public const DEFAULT_IMPACT = 'medium';

	/**
	 * What a case takes when neither it nor its case type says otherwise.
	 */
	public const DEFAULT_URGENCY = 'medium';

	/**
	 * The instance default matrix, keyed impact then urgency.
	 *
	 * Nine cells onto four values, scored rather than hand-picked: low counts
	 * 1, medium 2, high 3, and the two are added. A sum of 2 or 3 is `low`, 4
	 * is `normal`, 5 is `high`, 6 is `urgent`. That makes the matrix symmetric
	 * (high impact with low urgency reads the same as low impact with high
	 * urgency), monotone in both directions, and reachable on every one of the
	 * four values, which a hand-picked table usually is not.
	 *
	 * MEDIUM AND MEDIUM IS `normal` ON PURPOSE. Every case in this app carries
	 * `normal` today, written as a literal by the intake services. A default
	 * matrix whose centre cell said anything else would silently re-grade the
	 * whole caseload on the day it shipped.
	 *
	 * A case type overlays the cells it cares about; the rest stay as they are
	 * here.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const DEFAULT_MATRIX = [
		'high' => ['low' => 'normal', 'medium' => 'high', 'high' => 'urgent'],
		'medium' => ['low' => 'low', 'medium' => 'normal', 'high' => 'high'],
		'low' => ['low' => 'low', 'medium' => 'low', 'high' => 'normal'],
	];

	/**
	 * The impact and urgency pair that derives each priority, for a writer
	 * that has a priority in hand and needs the two facts behind it.
	 *
	 * Seed data and fixtures name a priority, because that is the thing a
	 * person reading the demo caseload sees. They cannot write it directly any
	 * more: the derivation recomputes `priority` on every save, so a seeded
	 * `urgent` would have been silently flattened to the default on the way
	 * in. Writing the pair instead means the seeded priority is the priority
	 * that comes back out, through the same matrix as everything else.
	 *
	 * @var array<string, array{impact: string, urgency: string}>
	 */
	public const PRIORITY_SEED_PAIRS = [
		'low' => ['impact' => 'medium', 'urgency' => 'low'],
		'normal' => ['impact' => 'medium', 'urgency' => 'medium'],
		'high' => ['impact' => 'high', 'urgency' => 'medium'],
		'urgent' => ['impact' => 'high', 'urgency' => 'high'],
	];

	/**
	 * The declared term rule, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $raiseRule = null;

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $resolver The effective blueprint of a case type,
	 *                                   so a child type inherits its parent's
	 *                                   matrix without declaring one.
	 * @param LoggerInterface  $logger   Structured logger.
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The priority a matrix gives for one impact and one urgency.
	 *
	 * An unreadable pair falls back to the declared defaults rather than to
	 * nothing: a case with no priority at all drops out of every sorted list,
	 * which is the failure this whole change exists to end.
	 *
	 * @param string                                 $impact  One of IMPACT_VALUES.
	 * @param string                                 $urgency One of URGENCY_VALUES.
	 * @param array<string, array<string, string>>   $matrix  The matrix to read,
	 *                                                        or an empty array
	 *                                                        for the instance
	 *                                                        default.
	 *
	 * @return string One of PRIORITY_VALUES.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function derive(string $impact, string $urgency, array $matrix = []): string {
		$impact = $this->normaliseImpact(value: $impact);
		$urgency = $this->normaliseUrgency(value: $urgency);

		if ($matrix === []) {
			$matrix = self::DEFAULT_MATRIX;
		}

		$derived = (string)($matrix[$impact][$urgency] ?? '');
		if (in_array($derived, self::PRIORITY_VALUES, true) === true) {
			return $derived;
		}

		return (string)(self::DEFAULT_MATRIX[$impact][$urgency] ?? 'normal');
	}//end derive()

	/**
	 * The matrix a case type derives by.
	 *
	 * The case type declares its cells as a list of `{impact, urgency,
	 * priority}` rows, because a form can edit a list and cannot edit a map
	 * of maps. Declared cells overlay the instance default rather than
	 * replacing it, so a case type that cares about one corner of the matrix
	 * states that corner and inherits the rest.
	 *
	 * @param string $caseTypeId The case's case type, or the empty string.
	 *
	 * @return array<string, array<string, string>> The matrix, keyed impact
	 *                                              then urgency.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function matrixFor(string $caseTypeId): array {
		if (trim($caseTypeId) === '') {
			return self::DEFAULT_MATRIX;
		}

		$cells = [];
		try {
			$effective = $this->resolver->effectiveCaseType(caseTypeId: trim($caseTypeId));
			$cells = (array)($effective['priorityMatrix'] ?? []);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read a case type priority matrix, using the instance default',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return self::DEFAULT_MATRIX;
		}

		return $this->matrixFromCells(cells: $cells);
	}//end matrixFor()

	/**
	 * The impact and urgency a case takes when it is created without them.
	 *
	 * @param string $caseTypeId The case's case type, or the empty string.
	 *
	 * @return array{impact: string, urgency: string} The declared defaults.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function defaultsFor(string $caseTypeId): array {
		$defaults = ['impact' => self::DEFAULT_IMPACT, 'urgency' => self::DEFAULT_URGENCY];
		if (trim($caseTypeId) === '') {
			return $defaults;
		}

		try {
			$effective = $this->resolver->effectiveCaseType(caseTypeId: trim($caseTypeId));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read case type priority defaults',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return $defaults;
		}

		$impact = (string)($effective['defaultImpact'] ?? '');
		$urgency = (string)($effective['defaultUrgency'] ?? '');

		if (in_array($impact, self::IMPACT_VALUES, true) === true) {
			$defaults['impact'] = $impact;
		}

		if (in_array($urgency, self::URGENCY_VALUES, true) === true) {
			$defaults['urgency'] = $urgency;
		}

		return $defaults;
	}//end defaultsFor()

	/**
	 * The declared order of a priority value.
	 *
	 * @param string $priority One of PRIORITY_VALUES.
	 *
	 * @return integer The order, 0 when the value is not one of them.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function orderOf(string $priority): int {
		return (int)(self::PRIORITY_ORDER[$priority] ?? 0);
	}//end orderOf()

	/**
	 * The higher of two priorities, which is the only direction a rule moves.
	 *
	 * @param string $current The priority the case holds.
	 * @param string $floor   The priority a rule asks for.
	 *
	 * @return string The higher of the two, by the declared order.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function raise(string $current, string $floor): string {
		if (in_array($floor, self::PRIORITY_VALUES, true) === false) {
			return $current;
		}

		if (in_array($current, self::PRIORITY_VALUES, true) === false) {
			return $floor;
		}

		return ($this->orderOf(priority: $floor) > $this->orderOf(priority: $current)) ? $floor : $current;
	}//end raise()

	/**
	 * The rule dossiq declares and OpenRegister's engine evaluates.
	 *
	 * @return array<string, mixed> The declaration, or an empty array when the
	 *                              file is unreadable.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function termRaiseRule(): array {
		if ($this->raiseRule !== null) {
			return $this->raiseRule;
		}

		$path = (__DIR__ . '/../Settings/priority_raise_rule.json');
		$raw = false;
		if (is_readable($path) === true) {
			$raw = file_get_contents($path);
		}

		if (is_string($raw) === false) {
			$this->logger->warning('Dossiq: the priority raise rule declaration is unreadable', ['path' => $path]);
			$this->raiseRule = [];
			return $this->raiseRule;
		}

		$decoded = json_decode($raw, true);
		$this->raiseRule = (is_array($decoded) === true) ? $decoded : [];

		return $this->raiseRule;
	}//end termRaiseRule()

	/**
	 * The floor the declared rule sets for a termijn threshold.
	 *
	 * This is the whole of dossiq's part in the rule: a lookup in the table
	 * the declaration carries. When OpenRegister's engine fires the rung for
	 * a threshold, the case is lifted to at least this value; a threshold the
	 * declaration does not name lifts nothing.
	 *
	 * @param integer $daysToTerm The threshold bucket the engine fired
	 *                            (14 / 7 / 2 / 0).
	 *
	 * @return string One of PRIORITY_VALUES, or the empty string for no floor.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function termRaiseFloor(int $daysToTerm): string {
		$thresholds = (array)($this->termRaiseRule()['thresholds'] ?? []);

		$floor = '';
		foreach ($thresholds as $threshold) {
			if (is_array($threshold) === false) {
				continue;
			}

			if ((int)($threshold['daysToTerm'] ?? -1) !== $daysToTerm) {
				continue;
			}

			$candidate = (string)($threshold['minimumPriority'] ?? '');
			if (in_array($candidate, self::PRIORITY_VALUES, true) === true) {
				$floor = $candidate;
			}
		}

		return $floor;
	}//end termRaiseFloor()

	/**
	 * The whole priority block a case should carry, given what it holds now.
	 *
	 * One function rather than three, because the three answers have to agree:
	 * `priority` is what everything reads, `priorityOrder` is what a list
	 * sorts on, and the two disagreeing is a queue that sorts differently from
	 * how it reads.
	 *
	 * @param array<string, mixed> $case The case payload as it is being saved.
	 *
	 * @return array<string, mixed> The fields to write back onto the case.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function resolve(array $case): array {
		$caseTypeId = $this->referenceId(value: ($case['caseType'] ?? null));
		$defaults = $this->defaultsFor(caseTypeId: $caseTypeId);

		$impact = $this->normaliseImpact(value: (string)($case['impact'] ?? ''), fallback: $defaults['impact']);
		$urgency = $this->normaliseUrgency(value: (string)($case['urgency'] ?? ''), fallback: $defaults['urgency']);

		$derived = $this->derive(
			impact: $impact,
			urgency: $urgency,
			matrix: $this->matrixFor(caseTypeId: $caseTypeId)
		);

		$effective = $this->raise(current: $derived, floor: (string)($case['priorityFloor'] ?? ''));

		$override = (string)($case['priorityOverride'] ?? '');
		if (in_array($override, self::PRIORITY_VALUES, true) === true) {
			$effective = $override;
		}

		return [
			'impact' => $impact,
			'urgency' => $urgency,
			'priorityDerived' => $derived,
			'priority' => $effective,
			'priorityOrder' => $this->orderOf(priority: $effective),
		];
	}//end resolve()

	/**
	 * The impact and urgency behind a priority a writer already has.
	 *
	 * @param string $priority One of PRIORITY_VALUES.
	 *
	 * @return array{impact: string, urgency: string} The pair that derives it
	 *                                                under the instance
	 *                                                default matrix.
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function pairFor(string $priority): array {
		return (self::PRIORITY_SEED_PAIRS[trim($priority)]
			?? self::PRIORITY_SEED_PAIRS['normal']);
	}//end pairFor()

	/**
	 * A matrix built from a case type's declared cells, over the default.
	 *
	 * @param array<int, mixed> $cells The declared `{impact, urgency, priority}` rows.
	 *
	 * @return array<string, array<string, string>> The matrix.
	 */
	private function matrixFromCells(array $cells): array {
		$matrix = self::DEFAULT_MATRIX;

		foreach ($cells as $cell) {
			if (is_array($cell) === false) {
				continue;
			}

			$impact = (string)($cell['impact'] ?? '');
			$urgency = (string)($cell['urgency'] ?? '');
			$priority = (string)($cell['priority'] ?? '');

			if (in_array($impact, self::IMPACT_VALUES, true) === false
				|| in_array($urgency, self::URGENCY_VALUES, true) === false
				|| in_array($priority, self::PRIORITY_VALUES, true) === false
			) {
				continue;
			}

			$matrix[$impact][$urgency] = $priority;
		}

		return $matrix;
	}//end matrixFromCells()

	/**
	 * An impact value, or the fallback when the stored one is not one.
	 *
	 * @param string $value    The stored value.
	 * @param string $fallback What to take instead.
	 *
	 * @return string One of IMPACT_VALUES.
	 */
	private function normaliseImpact(string $value, string $fallback = self::DEFAULT_IMPACT): string {
		$value = trim($value);
		if (in_array($value, self::IMPACT_VALUES, true) === true) {
			return $value;
		}

		return (in_array($fallback, self::IMPACT_VALUES, true) === true) ? $fallback : self::DEFAULT_IMPACT;
	}//end normaliseImpact()

	/**
	 * An urgency value, or the fallback when the stored one is not one.
	 *
	 * @param string $value    The stored value.
	 * @param string $fallback What to take instead.
	 *
	 * @return string One of URGENCY_VALUES.
	 */
	private function normaliseUrgency(string $value, string $fallback = self::DEFAULT_URGENCY): string {
		$value = trim($value);
		if (in_array($value, self::URGENCY_VALUES, true) === true) {
			return $value;
		}

		return (in_array($fallback, self::URGENCY_VALUES, true) === true) ? $fallback : self::DEFAULT_URGENCY;
	}//end normaliseUrgency()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()
}//end class
