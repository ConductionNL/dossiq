<?php

/**
 * A status holds only so many cases, and the guard is shown able to refuse.
 *
 * 🔴 THE REFUSAL IS THE REQUIREMENT. Gap register row Q3.22 was measured
 * rather than argued: Kanboard colours a full column's header and lets a
 * second card into a column with a limit of one; Vikunja refuses it. So a test
 * that only proved the guard passes on a status with room would pass against a
 * guard that never refuses anything, which is the losing product.
 *
 * 🔴 THE BOUNDARY IS ASSERTED ON BOTH SIDES. "at the limit" and "past the
 * limit" differ by one case, and an off-by-one here is a status that takes
 * thirteen or one that refuses the twelfth. Both are asserted against the same
 * limit in the same test.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The limit, the boundary, and the ways it must not bite.
 *
 * @covers \OCA\Dossiq\Service\Transitions\CapacityGuard
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Transitions\CaseTypeReader
 * @uses \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 */
class CapacityGuardTest extends TestCase {

	/**
	 * The status under test.
	 *
	 * @var string
	 */
	private const TARGET = 'status-in-behandeling';

	/**
	 * Build the guard over an in-memory store.
	 *
	 * @param int                              $capacity The limit on the target status.
	 * @param array<int, array<string, mixed>> $cases    The cases the store holds in it.
	 * @param bool                             $isFinal  Whether the target closes the case.
	 * @param bool                             $readable Whether the store answers at all.
	 *
	 * @return CapacityGuard The guard under test.
	 */
	private function makeGuard(
		int $capacity,
		array $cases,
		bool $isFinal = false,
		bool $readable = true,
	): CapacityGuard {
		$statuses = $this->createMock(StatusTypeLookup::class);
		$statuses->method('rowFor')->willReturn(
			['id' => self::TARGET, 'name' => 'In behandeling', 'capacity' => $capacity]
		);
		$statuses->method('nameFor')->willReturn('In behandeling');

		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('isFinalStatus')->willReturn($isFinal);

		$objectService = new class($cases) {
			/**
			 * @param array<int, array<string, mixed>> $cases The stored cases.
			 */
			public function __construct(private array $cases) {
			}//end __construct()

			/**
			 * Answer the cases in the filtered status.
			 *
			 * @param array<string, mixed> $params The query.
			 *
			 * @return array<string, mixed> The paginated answer.
			 */
			public function findAll(array $params = []): array {
				$wanted = (string)($params['filters']['status'] ?? '');

				$rows = array_values(
					array_filter(
						$this->cases,
						static fn (array $row): bool => ($row['status'] ?? '') === $wanted
					)
				);

				return ['results' => $rows, 'total' => count($rows)];
			}//end findAll()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($readable === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					default => $default,
				};
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				return vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $parameters);
			}
		);

		return new CapacityGuard($statuses, $caseTypes, $settings, $l10n);
	}//end makeGuard()

	/**
	 * A number of cases sitting in the target status.
	 *
	 * @param int $howMany How many.
	 *
	 * @return array<int, array<string, mixed>> The case rows.
	 */
	private function sitting(int $howMany): array {
		$rows = [];
		for ($i = 0; $i < $howMany; $i++) {
			$rows[] = ['id' => sprintf('case-%d', $i), 'status' => self::TARGET];
		}

		return $rows;
	}//end sitting()

	/**
	 * The guard config the engine appends.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return ['type' => 'statusCapacity', 'toStatus' => self::TARGET];
	}//end config()

	/**
	 * The case past the limit is refused; the one at it is not.
	 *
	 * @return void
	 */
	public function testTheCasePastTheLimitIsRefused(): void {
		$past = $this->makeGuard(3, $this->sitting(3))
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		$this->assertFalse($past->passed, 'a fourth case into a status of three must be refused');

		// The other side of the same boundary, against the same limit. Without
		// it an off-by-one reads as a pass.
		$at = $this->makeGuard(3, $this->sitting(2))
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		$this->assertTrue($at->passed, 'a third case into a status of three must be allowed');
	}//end testTheCasePastTheLimitIsRefused()

	/**
	 * The refusal names the status, the limit and the count.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheNumbers(): void {
		$result = $this->makeGuard(12, $this->sitting(12))
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		$this->assertFalse($result->passed);
		$this->assertStringContainsString('In behandeling', (string)$result->failureMessage);
		$this->assertStringContainsString('12', (string)$result->failureMessage);
		$this->assertSame(12, $result->details['capacity']);
		$this->assertSame(12, $result->details['count']);
	}//end testTheRefusalNamesTheNumbers()

	/**
	 * A status with no capacity takes as many as arrive.
	 *
	 * @return void
	 */
	public function testAStatusWithoutACapacityIsUnchanged(): void {
		$result = $this->makeGuard(0, $this->sitting(100))
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		$this->assertTrue($result->passed, 'zero means no limit, which is every status today');
	}//end testAStatusWithoutACapacityIsUnchanged()

	/**
	 * A final status is never capped.
	 *
	 * @return void
	 */
	public function testAFinalStatusIsNeverCapped(): void {
		$result = $this->makeGuard(3, $this->sitting(9), isFinal: true)
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		// A case type that could not close its thirteenth case would be worse
		// off than one with no limit at all.
		$this->assertTrue($result->passed);
		$this->assertTrue($result->details['final']);
	}//end testAFinalStatusIsNeverCapped()

	/**
	 * A transition naming no target is not this guard's business.
	 *
	 * @return void
	 */
	public function testATransitionWithNoTargetPasses(): void {
		$result = $this->makeGuard(3, $this->sitting(9))
			->evaluate(['type' => 'statusCapacity'], ['id' => 'case-new'], 'behandelaar');

		$this->assertTrue($result->passed);
	}//end testATransitionWithNoTargetPasses()

	/**
	 * A case does not take a seat from itself.
	 *
	 * @return void
	 */
	public function testACaseDoesNotCountAgainstItsOwnSeat(): void {
		$sitting = $this->sitting(3);

		// The case being moved is already one of the three, which is what a
		// self-transition or a re-run of a landed transition looks like.
		$result = $this->makeGuard(3, $sitting)
			->evaluate($this->config(), ['id' => 'case-0'], 'behandelaar');

		$this->assertTrue(
			$result->passed,
			'a case already in the status must not be refused for occupying its own seat'
		);
		$this->assertSame(2, $result->details['count']);
	}//end testACaseDoesNotCountAgainstItsOwnSeat()

	/**
	 * An unreadable store allows the move.
	 *
	 * @return void
	 */
	public function testAnUnreadableCountAllowsTheMove(): void {
		$result = $this->makeGuard(1, $this->sitting(9), readable: false)
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		// A capacity is a planning aid, not an authorization. Refusing work
		// because a read failed would stop a desk over an outage, and the
		// statutory term keeps running either way.
		$this->assertTrue($result->passed);
		$this->assertTrue($result->details['unreadable']);
	}//end testAnUnreadableCountAllowsTheMove()

	/**
	 * Cases in other statuses are not counted.
	 *
	 * @return void
	 */
	public function testOnlyCasesInThatStatusAreCounted(): void {
		$cases = array_merge(
			$this->sitting(2),
			[
				['id' => 'elsewhere-1', 'status' => 'status-afgehandeld'],
				['id' => 'elsewhere-2', 'status' => 'status-ontvangen'],
			]
		);

		$result = $this->makeGuard(3, $cases)
			->evaluate($this->config(), ['id' => 'case-new'], 'behandelaar');

		$this->assertTrue($result->passed);
		$this->assertSame(2, $result->details['count']);
	}//end testOnlyCasesInThatStatusAreCounted()

	/**
	 * The count is asked of the store by the target status.
	 *
	 * @return void
	 */
	public function testTheCountIsAskedByStatus(): void {
		$guard = $this->makeGuard(3, $this->sitting(3));

		$this->assertSame(3, $guard->countIn(statusTypeId: self::TARGET));
		$this->assertSame(2, $guard->countIn(statusTypeId: self::TARGET, excluding: 'case-0'));
		$this->assertSame(0, $guard->countIn(statusTypeId: 'status-nobody-is-in'));
	}//end testTheCountIsAskedByStatus()
}//end class
