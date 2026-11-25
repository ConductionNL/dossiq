<?php

/**
 * A proposed inspection schedule for a supervision plan, nothing written.
 *
 * "Suggest a schedule" proposes dates from each category's frequency per risk
 * profile and the plan's capacity, and only proposes: the caller shows the
 * dates for approval, and no inspection is planned until the supervisor
 * accepts. The rule:
 * - a category's demand is objects times visits per risk profile, rounded up
 *   per profile (its planned count when it names no frequency);
 * - when the demand in hours exceeds the plan's capacity, every category is cut
 *   by the same share, so the shortfall is spread rather than landing on the
 *   category that happens to come last, and the shortfall is reported;
 * - a category's inspections are spread evenly over the working days (Monday
 *   to Friday) from the start date to the end of the plan's year, highest risk
 *   profile first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

use DateInterval;
use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Propose dates per category from risk frequency and capacity.
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
 */
final class SupervisionScheduleProposal {

	/**
	 * The risk profiles, highest first: the order their visits are dated in.
	 */
	private const PROFILES = ['high', 'medium', 'low'];

	/**
	 * Constructor.
	 *
	 * @param SupervisionPlanProgress $progress The demand rule and record ids.
	 * @param ITimeFactory            $time     Today, the first day when none is given.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	public function __construct(
		private readonly SupervisionPlanProgress $progress,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The proposal for one plan.
	 *
	 * @param array<string, mixed>       $plan       The supervisionPlan record.
	 * @param list<array<string, mixed>> $categories Its supervisionPlanCategory rows.
	 * @param string                     $from       The first day dates may fall on (Y-m-d), today when it is not a date;
	 *                                               a day before the plan's year means 1 January.
	 *
	 * @return array<string, mixed> The dates per category, the hours asked and available, and whether anything was cut.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	public function propose(array $plan, array $categories, string $from=''): array {
		$start    = $this->firstDay(from: $from);
		$year     = (int)($plan['year'] ?? (int)$start->format('Y'));
		$capacity = max(0.0, (float)($plan['capacityHours'] ?? 0));
		$demand   = 0.0;
		foreach ($categories as $category) {
			$demand += $this->hoursOf(category: $category, count: $this->progress->demandOf(category: $category));
		}

		$share    = $this->share(capacity: $capacity, demand: $demand);
		$workdays = $this->workdays(year: $year, from: $start);
		$rows     = [];
		foreach ($categories as $category) {
			$rows[] = $this->categoryProposal(category: $category, share: $share, workdays: $workdays);
		}

		$proposedHours = 0.0;
		foreach ($rows as $index => $row) {
			$proposedHours += $this->hoursOf(category: $categories[$index], count: $row['proposed']);
		}

		return [
			'year'           => $year,
			'capacityHours'  => $capacity,
			'demandHours'    => $demand,
			'proposedHours'  => $proposedHours,
			'shortfallHours' => max(0.0, $demand - $capacity),
			'cut'            => $share < 1.0,
			'written'        => false,
			'categories'     => $rows,
		];
	}//end propose()

	/**
	 * The given Y-m-d as a day, else today.
	 *
	 * @param string $from The requested day.
	 *
	 * @return DateTimeImmutable The day.
	 */
	private function firstDay(string $from): DateTimeImmutable {
		$isDay = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $part) === 1;
		if ($isDay === true && checkdate((int)$part[2], (int)$part[3], (int)$part[1]) === true) {
			return new DateTimeImmutable($from);
		}

		return $this->time->now();
	}//end firstDay()

	/**
	 * The share of the demand the capacity covers, 1 when it covers all of it.
	 *
	 * A plan without capacity hours is not cut: an unset capacity is not a
	 * capacity of zero.
	 *
	 * @param float $capacity The capacity in hours.
	 * @param float $demand   The demand in hours.
	 *
	 * @return float Between 0 and 1.
	 */
	private function share(float $capacity, float $demand): float {
		if ($capacity <= 0.0 || $demand <= $capacity) {
			return 1.0;
		}

		return $capacity / $demand;
	}//end share()

	/**
	 * The hours a number of inspections in a category takes.
	 *
	 * @param array<string, mixed> $category The category.
	 * @param int                  $count    The inspections.
	 *
	 * @return float The hours.
	 */
	private function hoursOf(array $category, int $count): float {
		return $count * max(0.0, (float)($category['hoursPerInspection'] ?? 0));
	}//end hoursOf()

	/**
	 * One category's proposed visits, dated.
	 *
	 * @param array<string, mixed> $category The category.
	 * @param float                $share    The share of the demand that fits.
	 * @param list<string>         $workdays The working days left in the year.
	 *
	 * @return array<string, mixed> The category's proposal, with its first and last visit date ('' when none) so a
	 *                              table row can say the span without reading the visits.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	private function categoryProposal(array $category, float $share, array $workdays): array {
		$visits = [];
		foreach ($this->profiles(category: $category) as $profile => $count) {
			$visits = array_merge($visits, array_fill(0, (int)floor($count * $share), $profile));
		}

		$dated = [];
		$slots = count($workdays);
		foreach ($visits as $index => $profile) {
			if ($slots === 0) {
				break;
			}

			$dated[] = ['date' => $workdays[intdiv($index * $slots, count($visits))], 'riskProfile' => $profile];
		}

		return [
			'id'        => $this->progress->idOf(record: $category),
			'name'      => (string)($category['name'] ?? ''),
			'demand'    => $this->progress->demandOf(category: $category),
			'proposed'  => count($dated),
			'firstDate' => (string)($dated[0]['date'] ?? ''),
			'lastDate'  => (string)($dated[count($dated) - 1]['date'] ?? ''),
			'visits'    => $dated,
		];
	}//end categoryProposal()

	/**
	 * The visits a category asks for per risk profile, highest risk first.
	 *
	 * @param array<string, mixed> $category The category.
	 *
	 * @return array<string, int> Visits per profile; one `unrated` entry when the category names no frequency.
	 */
	private function profiles(array $category): array {
		$rows = $category['riskFrequency'] ?? [];
		if (is_array($rows) === false || $rows === []) {
			return ['unrated' => $this->progress->demandOf(category: $category)];
		}

		$counts = [];
		foreach (self::PROFILES as $profile) {
			foreach ($rows as $row) {
				if (is_array($row) === true && ($row['profile'] ?? '') === $profile) {
					$visits = max(0, (int)($row['objects'] ?? 0)) * max(0.0, (float)($row['visitsPerYear'] ?? 0));
					$counts[$profile] = ($counts[$profile] ?? 0) + (int)ceil($visits);
				}
			}
		}

		return $counts;
	}//end profiles()

	/**
	 * The working days (Monday to Friday) from a day to the end of a year, as Y-m-d.
	 *
	 * @param int               $year The plan's year.
	 * @param DateTimeImmutable $from The first day.
	 *
	 * @return list<string> The days; none when the year is over.
	 */
	private function workdays(int $year, DateTimeImmutable $from): array {
		$day = $from->setTime(0, 0);
		$jan = $from->setDate($year, 1, 1)->setTime(0, 0);
		if ($day < $jan) {
			$day = $jan;
		}

		$end  = $from->setDate($year, 12, 31)->setTime(0, 0);
		$days = [];
		$step = new DateInterval('P1D');
		while ($day <= $end) {
			if ((int)$day->format('N') <= 5) {
				$days[] = $day->format('Y-m-d');
			}

			$day = $day->add($step);
		}

		return $days;
	}//end workdays()
}//end class
