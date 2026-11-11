<?php

/**
 * Golden test for TermRearm::forDefinition: every call on the term writer, in
 * order, every log line and every answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermRearm;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class TermRearmGoldenTest extends TestCase {

	private TermijnService&MockObject $terms;

	private object $objects;

	private array $calls = [];

	private array $logged = [];

	private string $eventSchema = 'events';

	private array $runningFor = [];

	protected function setUp(): void {
		$this->calls   = [];
		$this->logged  = [];
		$this->objects = new class {
			public array $rows = [];

			public array $searches = [];

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				if (($this->rows[$filters['deadlineInstance']] ?? null) === 'fail') {
					throw new RuntimeException('events down');
				}

				return ($this->rows[$filters['deadlineInstance']] ?? []);
			}
		};

		$this->terms = $this->createMock(TermijnService::class);
		$calls       = &$this->calls;
		$runningFor  = &$this->runningFor;
		$this->terms->method('instancesForCase')->willReturnCallback(
			static function (string $caseId) use (&$runningFor): array {
				return ($runningFor[$caseId] ?? []);
			}
		);
		$this->terms->method('getTermijnDefinitie')->willReturnCallback(
			static function (string $caseType) use (&$calls): ?array {
				$calls[] = ['definitie', $caseType];
				return ($caseType === 'none') ? null : ['id' => 'def'];
			}
		);
		$this->terms->method('createTermijnInstance')->willReturnCallback(
			static function (string $caseId, string $caseType, $startDate = null) use (&$calls): array {
				$calls[] = ['create', $caseId, $caseType, $startDate];
				if ($caseId === 'case-fail') {
					throw new NoTermijnDefinitieException('no definition');
				}

				return ($caseId === 'case-noid') ? [] : ['id' => 'new-' . count($calls), 'endDateCalculated' => '2026-08-24'];
			}
		);
		$this->terms->method('markTermijnCompleted')->willReturnCallback(
			static function (string $termInstanceId, $voltooiDatum = null, string $documentLink = '', string $rationale = '') use (&$calls): ?array {
				$calls[] = ['complete', $termInstanceId, $voltooiDatum, $documentLink, $rationale];
				return null;
			}
		);
		$this->terms->method('recordEvent')->willReturnCallback(
			static function (...$args) use (&$calls): ?array {
				$calls[] = ['event', $args];
				return null;
			}
		);
		$this->terms->method('updateTermijnInstance')->willReturnCallback(
			static function (string $termInstanceId, array $patch) use (&$calls): ?array {
				$calls[] = ['update', $termInstanceId, $patch];
				return null;
			}
		);
	}//end setUp()

	public function testNothingRunningAsksNothing(): void {
		$this->running([['id' => 't1', 'status' => 'afgerond'], ['id' => 't2']]);

		$this->assertSame(['rearmed' => 0, 'kept' => 0, 'note' => ''], $this->rearm()->forDefinition('case-1', 'bezwaar', 'fout'));
		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->logged);
	}//end testNothingRunningAsksNothing()

	public function testNoTargetDefinitionKeepsTheClocks(): void {
		$this->running([['id' => 't1', 'status' => 'lopend'], ['id' => 't2', 'status' => 'lopend']]);

		$this->assertSame(
			[
				'rearmed' => 0,
				'kept' => 2,
				'note' => 'The target case type has no active term definition, so this case\'s running terms '
					. 'were left as they are rather than closed with nothing to replace them.',
			],
			$this->rearm()->forDefinition('case-1', 'none', 'fout')
		);
		$this->assertSame([['definitie', 'none']], $this->calls);
		$this->assertSame(
			[
				[
					'warning',
					'TermRearm.forDefinition: the target case type has no active term definition, '
						. 'so the running terms were left on the definition they started under',
					['case' => 'case-1', 'caseType' => 'none', 'running' => 2],
				],
			],
			$this->logged
		);
	}//end testNoTargetDefinitionKeepsTheClocks()

	public function testEveryRunningTermIsRearmedWithItsEventsReplayed(): void {
		$this->running([['status' => 'lopend'], ['id' => 't2', 'status' => 'lopend', 'startDate' => '2026-06-01']]);
		$this->objects->rows['t2'] = [
			['type' => 'start', 'moment' => '2026-06-01', 'daysImpact' => 56],
			['type' => 'verdaging', 'moment' => '2026-06-10', 'daysImpact' => 14, 'rationale' => 'Awb', 'actor' => 'jan'],
			['type' => 'pauze', 'moment' => '2026-06-05', 'daysImpact' => 5, 'basis' => 'AWB 4:15'],
			['type' => 'voltooi', 'moment' => '2026-06-20'],
		];

		$this->assertSame(['rearmed' => 1, 'kept' => 1, 'note' => ''], $this->rearm()->forDefinition('case-1', 'vergunning', 'verkeerd zaaktype'));
		$this->assertSame([['dossiq', 'events', ['deadlineInstance' => 't2']]], $this->objects->searches);
		$this->assertSame(
			[
				['definitie', 'vergunning'],
				['create', 'case-1', 'vergunning', null],
				['complete', 't2', null, '', 'Termijn afgesloten bij herbinding naar een ander zaaktype: verkeerd zaaktype'],
				['event', ['new-2', 'pauze', 'AWB 4:15', '', 5, null, '', 'system', []]],
				['event', ['new-2', 'verdaging', 'AWB 4:14', 'Awb', 14, null, '', 'jan', []]],
				['update', 'new-2', ['endDateCurrent' => '2026-09-12', 'countExtensions' => 1]],
			],
			$this->calls
		);
		$this->assertSame([], $this->logged);
	}//end testEveryRunningTermIsRearmedWithItsEventsReplayed()

	public function testTheShiftIsSignedAndAnExtensionOfNoDaysStillCounts(): void {
		$this->running([['id' => 'a', 'status' => 'lopend'], ['id' => 'b', 'status' => 'lopend'], ['id' => 'c', 'status' => 'lopend']]);
		$this->objects->rows['a'] = [['type' => 'correctie', 'daysImpact' => -3]];
		$this->objects->rows['b'] = [['type' => 'verdaging', 'daysImpact' => 0]];
		$this->objects->rows['c'] = [['daysImpact' => 0]];

		$this->assertSame(['rearmed' => 3, 'kept' => 0, 'note' => ''], $this->rearm()->forDefinition('case-1', 'x', 'r'));
		$updates = array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'update'));
		$this->assertSame(
			[
				['update', 'new-2', ['endDateCurrent' => '2026-08-21', 'countExtensions' => 0]],
				['update', 'new-6', ['endDateCurrent' => '2026-08-24', 'countExtensions' => 1]],
			],
			$updates
		);
		$events = array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'event'));
		$this->assertSame(['correctie', 'verdaging', 'verdaging'], array_map(static fn (array $call): string => $call[1][1], $events));
		$this->assertSame(['AWB 4:14', 'AWB 4:14', 'AWB 4:14'], array_map(static fn (array $call): string => $call[1][2], $events));
	}//end testTheShiftIsSignedAndAnExtensionOfNoDaysStillCounts()

	public function testFailuresLeaveTheClockAlone(): void {
		$this->running([['id' => 't1', 'status' => 'lopend']], 'case-fail');

		$this->assertSame(['rearmed' => 0, 'kept' => 1, 'note' => ''], $this->rearm()->forDefinition('case-fail', 'x', 'r'));
		$this->assertSame([['definitie', 'x'], ['create', 'case-fail', 'x', null]], $this->calls);
		$this->assertSame(
			[
				[
					'error',
					'TermRearm.forDefinition: the successor term could not be created, so the running term was left alone',
					['case' => 'case-fail', 'instance' => 't1', 'error' => 'no definition'],
				],
			],
			$this->logged
		);
	}//end testFailuresLeaveTheClockAlone()

	public function testUnreadableEventsAndASuccessorWithoutIdReplayNothing(): void {
		$this->running([['id' => 't1', 'status' => 'lopend']], 'case-noid');
		$this->objects->rows['t1'] = [['type' => 'verdaging', 'daysImpact' => 4]];

		$this->assertSame(['rearmed' => 1, 'kept' => 0, 'note' => ''], $this->rearm()->forDefinition('case-noid', 'x', 'r'));
		$this->assertSame(['definitie', 'create', 'complete'], array_column($this->calls, 0));

		$this->calls = [];
		$this->running([['id' => 't9', 'status' => 'lopend']], 'case-2');
		$this->objects->rows['t9'] = 'fail';
		$this->assertSame(['rearmed' => 1, 'kept' => 0, 'note' => ''], $this->rearm()->forDefinition('case-2', 'x', 'r'));
		$this->assertSame(['definitie', 'create', 'complete'], array_column($this->calls, 0));
		$this->assertSame(
			[['warning', 'TermRearm.carriedEvents lookup failed, so no extension was carried forward', ['instance' => 't9', 'error' => 'events down']]],
			$this->logged
		);

		$this->logged      = [];
		$this->calls       = [];
		$this->eventSchema = '';
		$this->objects->searches = [];
		$this->assertSame(['rearmed' => 1, 'kept' => 0, 'note' => ''], $this->rearm()->forDefinition('case-2', 'x', 'r'));
		$this->assertSame([], $this->objects->searches);
		$this->assertSame([], $this->logged);
	}//end testUnreadableEventsAndASuccessorWithoutIdReplayNothing()

	private function running(array $instances, string $caseId = 'case-1'): void {
		$this->runningFor = [$caseId => $instances];
	}//end running()

	private function rearm(): TermRearm {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$schema = $this->eventSchema;
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_gebeurtenis_schema' => $schema,
				default => '',
			}
		);
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new TermRearm(terms: $this->terms, settingsService: $settings, logger: $logger);
	}//end rearm()
}//end class
