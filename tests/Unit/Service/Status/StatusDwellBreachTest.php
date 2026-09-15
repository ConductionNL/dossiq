<?php

/**
 * A dwell breach is not a term breach.
 *
 * Nine weeks in one status inside a term that runs for six months is exactly
 * the case this change exists to find: the term is comfortable, the case is
 * stuck, and until now nothing noticed. So the breach is its own fact, and the
 * test that earns its place is the one holding the two apart.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use DateTimeImmutable;
use OCA\Dossiq\Service\Status\StatusDwellService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusDwellService
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusDwellBreachTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service under test.
	 *
	 * @var StatusDwellService
	 */
	private StatusDwellService $dwell;

	/**
	 * Build the service over the real working-day calendar.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dwell = new StatusDwellService(
			calendar: new WorkingDayCalculator(),
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * Nine weeks in a status inside a healthy term.
	 *
	 * The maximum is four weeks of working days; the case entered on 1 June
	 * and is read on 6 July, twenty-five working days later. The term runs to
	 * 1 September and is nowhere near.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheDwellBreachesWhileTheTermIsHealthy(): void {
		$case = [
			'status' => 'review',
			'deadline' => '2026-09-01',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
		];

		$snapshot = $this->dwell->snapshot(
			case: $case,
			maximum: 20,
			now: new DateTimeImmutable('2026-07-06T09:00:00+02:00'),
		);

		self::assertSame(expected: 25, actual: $snapshot['days']);
		self::assertSame(expected: 20, actual: $snapshot['maximum']);
		self::assertTrue(condition: $snapshot['breached']);
		// The term is untouched by any of it.
		self::assertSame(expected: '2026-09-01', actual: $case['deadline']);
	}//end testTheDwellBreachesWhileTheTermIsHealthy()

	/**
	 * A status that declares no maximum never breaches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAStatusWithNoMaximumNeverBreaches(): void {
		$case = ['status' => 'review', 'currentStatusEnteredAt' => '2020-01-01T09:00:00+01:00'];

		self::assertFalse(
			condition: $this->dwell->isBreached(
				case: $case,
				maximum: null,
				now: new DateTimeImmutable('2026-07-06T09:00:00+02:00'),
			),
		);
	}//end testAStatusWithNoMaximumNeverBreaches()

	/**
	 * The day the maximum is reached is not yet a breach.
	 *
	 * Twenty working days on a maximum of twenty is a case that used its
	 * allowance exactly, and calling that a breach would put a flag on every
	 * case that met its own service level.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testReachingTheMaximumIsNotYetABreach(): void {
		$case = ['status' => 'review', 'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00'];

		// 1 June to 29 June is exactly twenty working days.
		self::assertSame(
			expected: 20,
			actual: $this->dwell->elapsedWorkingDays(
				case: $case,
				now: new DateTimeImmutable('2026-06-29T09:00:00+02:00'),
			),
		);
		self::assertFalse(
			condition: $this->dwell->isBreached(
				case: $case,
				maximum: 20,
				now: new DateTimeImmutable('2026-06-29T09:00:00+02:00'),
			),
		);
		self::assertTrue(
			condition: $this->dwell->isBreached(
				case: $case,
				maximum: 20,
				now: new DateTimeImmutable('2026-06-30T09:00:00+02:00'),
			),
		);
	}//end testReachingTheMaximumIsNotYetABreach()

	/**
	 * A move clears the breach, because it is about the status that was left.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAMoveClearsTheBreachFlag(): void {
		$case = [
			'status' => 'review',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
			'statusDwellBreached' => true,
		];

		$moved = $this->dwell->applyStatusChange(
			case: $case,
			toStatus: 'decision',
			now: new DateTimeImmutable('2026-07-06T09:00:00+02:00'),
		);

		self::assertFalse(condition: $moved['statusDwellBreached']);
	}//end testAMoveClearsTheBreachFlag()
}//end class
