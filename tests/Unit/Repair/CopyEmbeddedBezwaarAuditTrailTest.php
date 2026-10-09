<?php

/**
 * The embedded bezwaar audit entries arrive once, in order, and the arrays stay as written.
 *
 * Built on the real repair step and BezwaarAuditTrail, over one in-memory
 * store and a recording audit mapper whose findAll() filters the way
 * OpenRegister's does.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CopyEmbeddedBezwaarAuditTrail;
use OCA\Dossiq\Tests\Support\MakesBezwaarAuditTrail;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\CopyEmbeddedBezwaarAuditTrail
 *
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail
 * @uses \OCA\Dossiq\Service\Bezwaar\BezwaarEntryNotWrittenException
 */
class CopyEmbeddedBezwaarAuditTrailTest extends TestCase {
	use MakesBezwaarAuditTrail;

	/**
	 * Three June entries by handler-1.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const JUNE = [
		['event' => 'hearing-scheduled', 'tag' => 'awb-art-7:2', 'actor' => 'handler-1', 'at' => '2026-06-02T09:00:00+02:00', 'payload' => ['case' => 'case-1']],
		['event' => 'attendance-late-correction', 'tag' => 'awb-art-7:7', 'actor' => 'handler-1', 'at' => '2026-06-16T11:30:00+02:00', 'payload' => ['invitee' => 'person-1']],
		['event' => 'verslag-recorded', 'tag' => 'awb-art-7:7', 'actor' => 'handler-1', 'at' => '2026-06-17T10:00:00+02:00', 'payload' => ['hasSummary' => true]],
	];

	/**
	 * Warnings the step wrote to its output.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->startBezwaarStore();
		$this->store->seed('hearingSession', 'session-1', ['case' => 'case-1', 'auditTrail' => self::JUNE]);
		$this->store->seed('bacAdviceRequest', 'req-1', ['bezwaar' => 'bezwaar-1', 'auditTrail' => [
			['event' => 'panel-member-added', 'actor' => 'handler-2', 'at' => '2026-06-03T08:00:00+02:00', 'payload' => ['commissieId' => 'committee-1']],
		]]);
		$this->store->seed('hearingSession', 'session-empty', ['case' => 'case-2']);
	}

	/**
	 * Run the real step once.
	 *
	 * @return void
	 */
	private function runStep(): void {
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('openregister')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Db\\AuditTrailMapper')->willReturn($this->trail);

		(new CopyEmbeddedBezwaarAuditTrail(
			settingsService: $this->bezwaarSettings(),
			auditTrail: $this->bezwaarAuditTrail(uid: null),
			container: $container,
			appManager: $apps,
			logger: $this->createMock(LoggerInterface::class),
		))->run($output);
	}

	/**
	 * Three entries arrive once, in order, with their original time and actor (REQ-BAT-004).
	 *
	 * @return void
	 */
	public function testEveryEntryArrivesOnceInOrderWithItsOriginalTimeAndActor(): void {
		$this->runStep();

		$this->assertSame(
			['dossiq.bezwaar.hearing-scheduled', 'dossiq.bezwaar.attendance-late-correction', 'dossiq.bezwaar.verslag-recorded'],
			$this->trail->actionsOn('session-1')
		);
		$rows = array_values(array_filter($this->trail->rows, static fn (array $row): bool => $row['object'] === 'session-1'));
		foreach ($rows as $index => $row) {
			$this->assertSame(self::JUNE[$index] + ['migratedFrom' => 'auditTrail', 'migratedIndex' => $index], $row['context']);
			$this->assertSame('system', $row['actor'], 'the system wrote the copy');
		}

		$this->assertSame(['dossiq.bezwaar.panel-member-added'], $this->trail->actionsOn('req-1'));
		$this->assertSame([], $this->trail->actionsOn('session-empty'));
	}

	/**
	 * A second run writes nothing (REQ-BAT-004).
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$this->runStep();
		$first = count($this->trail->rows);
		$this->runStep();

		$this->assertSame(4, $first);
		$this->assertCount($first, $this->trail->rows, 'a second run must write nothing');
	}

	/**
	 * The arrays are not changed (REQ-BAT-004).
	 *
	 * @return void
	 */
	public function testTheArrayIsNotChanged(): void {
		$before = serialize($this->store->rows);
		$this->runStep();

		$this->assertSame($before, serialize($this->store->rows), 'the store, arrays included, must be byte for byte the same');
		$this->assertSame(0, $this->store->writes);
	}

	/**
	 * A record that cannot be written is reported by uuid and the run goes on (REQ-BAT-004).
	 *
	 * @return void
	 */
	public function testAnObjectThatCannotBeWrittenIsReportedAndTheRunGoesOn(): void {
		$this->trail->failingActions = ['dossiq.bezwaar.attendance-late-correction'];
		$this->runStep();

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('session-1', $this->warnings[0]);
		$this->assertSame(['dossiq.bezwaar.panel-member-added'], $this->trail->actionsOn('req-1'), 'the next record is still copied');

		// The entry before the failure was copied; a later run finishes the rest without duplicating it.
		$this->trail->failingActions = [];
		$this->runStep();
		$this->assertSame(
			['dossiq.bezwaar.hearing-scheduled', 'dossiq.bezwaar.attendance-late-correction', 'dossiq.bezwaar.verslag-recorded'],
			$this->trail->actionsOn('session-1')
		);
	}

	/**
	 * The step is registered under post-migration in appinfo/info.xml.
	 *
	 * @return void
	 */
	public function testTheCopyStepIsRegistered(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertNotFalse($info);
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$this->assertContains(CopyEmbeddedBezwaarAuditTrail::class, $steps);
	}
}
