<?php

/**
 * Dossiq TermStatusClock failure and declaration test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermStatusClock;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the clock does when the engine refuses, and how the declared statuses are read.
 *
 * @covers \OCA\Dossiq\Service\Term\TermStatusClock
 */
final class TermStatusClockEdgeTest extends TestCase {
	/**
	 * The instance patches written.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $patches = [];

	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A clock over one instance, one definition and an engine that may throw.
	 *
	 * @param array<string, mixed>|null $instance    The case's term instance.
	 * @param array<string, mixed>|null $definition  The case type's term definition.
	 * @param TermijnTimerService|null  $timers      The engine, or a quiet one.
	 *
	 * @return TermStatusClock
	 */
	private function clock(?array $instance, ?array $definition, ?TermijnTimerService $timers = null): TermStatusClock {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('getTermijnInstanceForZaak')->willReturn($instance);
		$terms->method('getTermijnDefinitie')->willReturnCallback(
			static fn (string $caseType): ?array => ($caseType === 'ct-permit' ? $definition : null)
		);
		$terms->method('updateTermijnInstance')->willReturnCallback(
			function (string $termInstanceId, array $patch): ?array {
				$this->patches[] = [$termInstanceId, $patch];

				return $patch;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new TermStatusClock(terms: $terms, timers: ($timers ?? $this->createMock(TermijnTimerService::class)), logger: $logger);
	}//end clock()

	/**
	 * A refused suspend leaves the clock and the instance flag untouched and warns.
	 *
	 * @return void
	 */
	public function testARefusedStopChangesNothing(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects($this->once())->method('suspendBeslistermijn')->willThrowException(new RuntimeException('engine down'));

		$result = $this->clock(['id' => 'ti-1', 'status' => 'lopend'], ['runsInStatuses' => ['st-open']], $timers)
			->reconcile('case-1', 'st-advice', 'ct-permit');

		$this->assertSame(TermStatusClock::UNCHANGED, $result);
		$this->assertSame([], $this->patches);
		$this->assertSame(['Dossiq: a term clock could not be stopped: engine down'], $this->warnings);
	}//end testARefusedStopChangesNothing()

	/**
	 * A refused resume keeps the instance marked as stopped and warns.
	 *
	 * @return void
	 */
	public function testARefusedStartChangesNothing(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects($this->once())->method('resumeBeslistermijn')->willThrowException(new RuntimeException('engine down'));

		$result = $this->clock(['id' => 'ti-1', 'status' => 'opgeschort', 'clockStoppedByStatus' => true], ['runsInStatuses' => ['st-open']], $timers)
			->reconcile('case-1', 'st-open', 'ct-permit');

		$this->assertSame(TermStatusClock::UNCHANGED, $result);
		$this->assertSame([], $this->patches);
		$this->assertSame(['Dossiq: a term clock could not be started again: engine down'], $this->warnings);
	}//end testARefusedStartChangesNothing()

	/**
	 * Stopping passes the fixed reason with no end date, and flags the instance by id.
	 *
	 * @return void
	 */
	public function testAStopPassesTheReasonAndFlagsTheInstance(): void {
		$instance = ['id' => 'ti-1', 'status' => 'verlengd'];
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects($this->once())->method('suspendBeslistermijn')
			->with($instance, 'Status buiten de lopende statussen van de termijn', null)
			->willReturn(true);

		$result = $this->clock($instance, ['runsInStatuses' => ['st-open']], $timers)->reconcile('case-1', 'st-advice', 'ct-permit');

		$this->assertSame(TermStatusClock::SUSPENDED, $result);
		$this->assertSame([['ti-1', ['clockStoppedByStatus' => true]]], $this->patches);
	}//end testAStopPassesTheReasonAndFlagsTheInstance()

	/**
	 * Declared statuses are trimmed and blanks dropped; a malformed or absent declaration runs everywhere.
	 *
	 * @return void
	 */
	public function testTheDeclarationIsReadDefensively(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects($this->never())->method('suspendBeslistermijn');
		$instance = ['id' => 'ti-1', 'status' => 'lopend'];

		$this->assertSame(TermStatusClock::UNCHANGED, $this->clock($instance, ['runsInStatuses' => [' st-open ', '', '  ']], $timers)->reconcile('c', 'st-open', ' ct-permit '));
		$this->assertSame(TermStatusClock::UNCHANGED, $this->clock($instance, ['runsInStatuses' => ['', ' ']], $timers)->reconcile('c', 'st-advice', 'ct-permit'));
		$this->assertSame(TermStatusClock::UNCHANGED, $this->clock($instance, ['runsInStatuses' => 'st-open'], $timers)->reconcile('c', 'st-advice', 'ct-permit'));
		$this->assertSame(TermStatusClock::UNCHANGED, $this->clock($instance, null, $timers)->reconcile('c', 'st-advice', 'ct-permit'));
		$this->assertSame(TermStatusClock::UNCHANGED, $this->clock($instance, ['runsInStatuses' => ['st-open']], $timers)->reconcile('c', 'st-advice', '  '));
	}//end testTheDeclarationIsReadDefensively()

	/**
	 * Only a literal true counts as stopped by status, so a truthy string is restarted by nothing.
	 *
	 * @return void
	 */
	public function testOnlyATrueFlagCountsAsStopped(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects($this->never())->method('resumeBeslistermijn');

		$result = $this->clock(['id' => 'ti-1', 'status' => 'opgeschort', 'clockStoppedByStatus' => 'true'], ['runsInStatuses' => ['st-open']], $timers)
			->reconcile('case-1', 'st-open', 'ct-permit');

		$this->assertSame(TermStatusClock::UNCHANGED, $result);
	}//end testOnlyATrueFlagCountsAsStopped()
}//end class
