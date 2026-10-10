<?php

/**
 * Tests for what happens when a beschikking's objection term ends.
 *
 * Ported from BezwaarTermijnJobTest, whose subject retires: the job decided
 * WHEN and WHAT together, the timer now decides when, and these assert the
 * what did not move. A received objection must never archive the
 * beschikking, and a refused archive must leave the trigger on, or a
 * beschikking under objection is filed away and one that failed is forgotten.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__.'/../BeschikkingServiceTest.php';

/**
 * Archive, objection, switched off, refused.
 */
class BezwaarArchiveTriggerTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var FakeObjectService
	 */
	private FakeObjectService $objects;

	/**
	 * The beschikking service.
	 *
	 * @var BeschikkingService&MockObject
	 */
	private BeschikkingService&MockObject $decisions;

	/**
	 * The service under test.
	 *
	 * @var BezwaarArchiveTrigger
	 */
	private BezwaarArchiveTrigger $trigger;

	/**
	 * Build the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects   = new FakeObjectService();
		$this->decisions = $this->createMock(BeschikkingService::class);
		$settings        = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'bezwaar_trigger_schema' => 'bezwaarTrigger',
				default => '',
			}
		);

		$this->trigger = new BezwaarArchiveTrigger(
			settingsService: $settings,
			decisions: $this->decisions,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Store one trigger.
	 *
	 * @param array<string, mixed> $fields Fields over the default lapsed trigger.
	 *
	 * @return array<string, mixed> The stored trigger.
	 */
	private function stored(array $fields = []): array {
		return $this->objects->saveObject(
			'dossiq',
			'bezwaarTrigger',
			array_merge(
				['id' => 'trig-1', 'decisionId' => 'besch-1', 'objectionReceived' => false, 'archiveTriggerActive' => true, 'archiveDate' => '2026-10-01'],
				$fields
			)
		);
	}//end stored()

	/**
	 * A lapsed term without objection archives and switches the trigger off.
	 *
	 * @return void
	 */
	public function testALapsedTermArchivesTheBeschikking(): void {
		$this->decisions->expects($this->once())->method('archive')->with('besch-1')->willReturn([]);

		$outcome = $this->trigger->process(trigger: $this->stored());

		$this->assertSame(expected: BezwaarArchiveTrigger::ARCHIVED, actual: $outcome);
		$this->assertFalse($this->objects->find('trig-1', 'dossiq', 'bezwaarTrigger')['archiveTriggerActive']);
	}//end testALapsedTermArchivesTheBeschikking()

	/**
	 * A received objection never archives; the trigger is switched off.
	 *
	 * @return void
	 */
	public function testAReceivedObjectionSkipsTheArchive(): void {
		$this->decisions->expects($this->never())->method('archive');

		$outcome = $this->trigger->process(trigger: $this->stored(fields: ['objectionReceived' => true]));

		$this->assertSame(expected: BezwaarArchiveTrigger::OBJECTED, actual: $outcome);
		$this->assertFalse($this->objects->find('trig-1', 'dossiq', 'bezwaarTrigger')['archiveTriggerActive']);
	}//end testAReceivedObjectionSkipsTheArchive()

	/**
	 * A trigger already switched off, or without a beschikking, is left alone.
	 *
	 * @return void
	 */
	public function testASwitchedOffTriggerIsLeftAlone(): void {
		$this->decisions->expects($this->never())->method('archive');

		$this->assertSame(
			expected: BezwaarArchiveTrigger::SKIPPED,
			actual: $this->trigger->process(trigger: $this->stored(fields: ['archiveTriggerActive' => false]))
		);
		$this->assertSame(
			expected: BezwaarArchiveTrigger::SKIPPED,
			actual: $this->trigger->process(trigger: $this->stored(fields: ['decisionId' => '']))
		);
	}//end testASwitchedOffTriggerIsLeftAlone()

	/**
	 * A refused archive leaves the trigger on, so the beschikking is not forgotten.
	 *
	 * @return void
	 */
	public function testARefusedArchiveLeavesTheTriggerOn(): void {
		$this->decisions->method('archive')->willThrowException(new RuntimeException('invalid_transition'));

		$outcome = $this->trigger->process(trigger: $this->stored());

		$this->assertSame(expected: BezwaarArchiveTrigger::FAILED, actual: $outcome);
		$this->assertTrue($this->objects->find('trig-1', 'dossiq', 'bezwaarTrigger')['archiveTriggerActive']);
	}//end testARefusedArchiveLeavesTheTriggerOn()

	/**
	 * The trigger is read fresh by id, and an unknown id reads as null.
	 *
	 * @return void
	 */
	public function testTheTriggerIsReadFresh(): void {
		$this->stored(fields: ['objectionReceived' => true]);

		$this->assertTrue($this->trigger->find(triggerId: 'trig-1')['objectionReceived']);
		$this->assertNull($this->trigger->find(triggerId: 'trig-none'));
	}//end testTheTriggerIsReadFresh()
}//end class
