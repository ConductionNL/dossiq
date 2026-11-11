<?php

/**
 * Golden test for TermCarryOver::carry: every outcome, and every call on the
 * term store and the timers, in order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Term\TermCarryOver;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TermCarryOverGoldenTest extends TestCase {

	private array $calls = [];

	private function carryOver(mixed $instances, ?string $timer = 'timer-1', ?array $written = null): TermCarryOver {
		$calls = &$this->calls;
		$terms = $this->createMock(TermijnService::class);
		$terms->method('instancesForCase')->willReturnCallback(
			static function (string $caseId) use ($instances): array {
				if ($instances === 'fail') {
					throw new RuntimeException('down');
				}

				return $instances;
			}
		);
		$terms->method('markTermijnCompleted')->willReturnCallback(
			static function (...$args) use (&$calls): ?array {
				$calls[] = ['complete', $args[0], ($args[1] instanceof DateTimeImmutable) ? $args[1]->format('Y-m-d') : $args[1], $args[3] ?? null];
				return null;
			}
		);
		$terms->method('getTermijnDefinitie')->willReturnCallback(
			static function (string $caseType) use (&$calls): ?array {
				$calls[] = ['definitie', $caseType];
				return ($caseType === 'none') ? null : ['legalBasis' => 'Awb 4:13'];
			}
		);
		$terms->method('updateTermijnInstance')->willReturnCallback(
			static function (string $termInstanceId, array $patch) use (&$calls, $written): ?array {
				$calls[] = ['update', $termInstanceId, $patch];
				return $written;
			}
		);
		$terms->method('recordEvent')->willReturnCallback(
			static function (...$args) use (&$calls): ?array {
				$calls[] = ['event', $args[0], $args[1], $args[2], $args[3], $args[4]];
				return null;
			}
		);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('cancelForInstance')->willReturnCallback(
			static function (string $instanceId, string $reason) use (&$calls): int {
				$calls[] = ['cancel', $instanceId, $reason];
				return 1;
			}
		);
		$timers->method('armBeslistermijn')->willReturnCallback(
			static function (array $instance, array $definitie) use (&$calls, $timer): ?string {
				$calls[] = ['arm', $instance, $definitie];
				return $timer;
			}
		);
		$timers->method('suspendBeslistermijn')->willReturnCallback(
			static function (array $instance, string $reason, ?DateTimeImmutable $until) use (&$calls): bool {
				$calls[] = ['suspend', $instance['engineTimerId'] ?? '', $reason, $until];
				return true;
			}
		);
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('tryParse')->willReturnCallback(
			static fn (mixed $value): ?DateTimeImmutable => (is_string($value) === true && $value !== '') ? new DateTimeImmutable($value) : null
		);
		$dates->method('toCalendarDateOrNull')->willReturnCallback(
			static fn (mixed $value): ?string => (is_string($value) === true && $value !== '') ? (new DateTimeImmutable($value))->format('Y-m-d') : null
		);

		return new TermCarryOver(terms: $terms, timers: $timers, dates: $dates);
	}//end carryOver()

	public function testNoStatutoryInstance(): void {
		$missing = ['outcome' => TermCarryOver::MISSING, 'instance' => '', 'timer' => ''];
		$this->assertSame($missing, $this->carryOver('fail')->carry('case-1', [], 'woo'));
		$this->assertSame($missing, $this->carryOver([['id' => 'a', 'kind' => 'internal'], ['id' => 'b', 'status' => 'completed'], ['kind' => 'statutory']])->carry('case-1', [], 'woo'));
		$this->assertSame([], $this->calls);
	}//end testNoStatutoryInstance()

	public function testClosedKeptCarriedAndNotArmed(): void {
		$instance = ['id' => 'ti-1', 'kind' => 'weird', 'status' => 'verlengd', 'countExtensions' => 2, 'endDateCurrent' => '2026-05-01', 'engineTimerId' => 'old'];
		$outcomes = [
			'closed' => $this->carryOver([$instance])->carry('case-1', ['state' => 'closed', 'closedOn' => '2026-04-02'], 'woo'),
			'closed-undated' => $this->carryOver([$instance])->carry('case-1', ['state' => 'closed'], 'woo'),
			'kept' => $this->carryOver([$instance])->carry('case-1', ['extensions' => 2, 'endDate' => '2026-05-01'], 'woo'),
			'kept-undated' => $this->carryOver([$instance])->carry('case-1', ['extensions' => '2'], 'woo'),
			'carried' => $this->carryOver([$instance], written: ['id' => 'ti-1', 'written' => true])->carry('case-1', ['state' => 'suspended', 'extensions' => 2, 'endDate' => '2026-06-01', 'extensionReason' => ' Advies '], 'woo', 14),
			'carried-fresh' => $this->carryOver([['id' => 'ti-2', 'status' => 'lopend']])->carry('case-2', ['extensions' => -3], 'none', -5),
			'not-armed' => $this->carryOver([['id' => 'ti-3']], timer: null)->carry('case-3', ['extensions' => 1], 'woo'),
		];

		$this->assertSame(
			[
				'closed' => ['outcome' => TermCarryOver::COMPLETED, 'instance' => 'ti-1', 'timer' => ''],
				'closed-undated' => ['outcome' => TermCarryOver::COMPLETED, 'instance' => 'ti-1', 'timer' => ''],
				'kept' => ['outcome' => TermCarryOver::KEPT, 'instance' => 'ti-1', 'timer' => 'old'],
				'kept-undated' => ['outcome' => TermCarryOver::KEPT, 'instance' => 'ti-1', 'timer' => 'old'],
				'carried' => ['outcome' => TermCarryOver::CARRIED, 'instance' => 'ti-1', 'timer' => 'timer-1'],
				'carried-fresh' => ['outcome' => TermCarryOver::CARRIED, 'instance' => 'ti-2', 'timer' => 'timer-1'],
				'not-armed' => ['outcome' => TermCarryOver::NOT_ARMED, 'instance' => 'ti-3', 'timer' => ''],
			],
			$outcomes
		);
		$reason = 'Termijn overgenomen uit het bronsysteem';
		$this->assertSame(
			[
				['complete', 'ti-1', '2026-04-02', $reason . ': daar al afgesloten'],
				['complete', 'ti-1', null, $reason . ': daar al afgesloten'],
				['definitie', 'woo'],
				['cancel', 'ti-1', $reason],
				['update', 'ti-1', ['countExtensions' => 2, 'status' => 'paused', 'engineTimerId' => '', 'endDateCurrent' => '2026-06-01']],
				['arm', ['id' => 'ti-1', 'written' => true], ['legalBasis' => 'Awb 4:13']],
				['update', 'ti-1', ['engineTimerId' => 'timer-1', 'timerBreachesAfterLastDay' => true]],
				['suspend', 'timer-1', $reason . ': opgeschort', null],
				['event', 'ti-1', 'verdaging', 'Awb 4:13', 'Advies', 14],
				['event', 'ti-1', 'verdaging', 'Awb 4:13', 'Advies', 14],
				['definitie', 'none'],
				['cancel', 'ti-2', $reason],
				['update', 'ti-2', ['countExtensions' => 0, 'status' => 'lopend', 'engineTimerId' => '']],
				['arm', ['id' => 'ti-2', 'status' => 'lopend', 'countExtensions' => 0, 'engineTimerId' => ''], []],
				['update', 'ti-2', ['engineTimerId' => 'timer-1', 'timerBreachesAfterLastDay' => true]],
				['definitie', 'woo'],
				['cancel', 'ti-3', $reason],
				['update', 'ti-3', ['countExtensions' => 1, 'status' => 'verlengd', 'engineTimerId' => '']],
				['arm', ['id' => 'ti-3', 'countExtensions' => 1, 'status' => 'verlengd', 'engineTimerId' => ''], ['legalBasis' => 'Awb 4:13']],
				['event', 'ti-3', 'verdaging', 'Awb 4:13', $reason, 0],
			],
			$this->calls
		);
	}//end testClosedKeptCarriedAndNotArmed()
}//end class
