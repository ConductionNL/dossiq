<?php

/**
 * Tests for the bezwaar archive trigger when its store is not configured or refuses a write.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeObjectService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__.'/../BeschikkingServiceTest.php';

/**
 * Unconfigured store and refused switch-off.
 */
#[CoversClass(BezwaarArchiveTrigger::class)]
class BezwaarArchiveTriggerConfigTest extends TestCase {

	/**
	 * The beschikking service.
	 *
	 * @var BeschikkingService&MockObject
	 */
	private BeschikkingService&MockObject $decisions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->decisions = $this->createMock(BeschikkingService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A trigger over the given store and configuration.
	 *
	 * @param object|null           $objects The object store, null when OpenRegister is absent.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return BezwaarArchiveTrigger The trigger.
	 */
	private function trigger(?object $objects, array $config): BezwaarArchiveTrigger {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new BezwaarArchiveTrigger(settingsService: $settings, decisions: $this->decisions, logger: $this->logger);
	}//end trigger()

	/**
	 * A lapsed trigger.
	 *
	 * @return array<string, mixed> The trigger.
	 */
	private function lapsed(): array {
		return ['id' => 'trig-1', 'decisionId' => 'besch-1', 'objectionReceived' => false, 'archiveTriggerActive' => true];
	}//end lapsed()

	/**
	 * Without a register, a schema, an object store or an id, nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreReadsNoTrigger(): void {
		$objects = new FakeObjectService();
		$full    = ['register' => 'dossiq', 'bezwaar_trigger_schema' => 'bezwaarTrigger'];

		$this->assertNull($this->trigger(objects: $objects, config: ['bezwaar_trigger_schema' => 'bezwaarTrigger'])->find(triggerId: 'trig-1'));
		$this->assertNull($this->trigger(objects: $objects, config: ['register' => 'dossiq'])->find(triggerId: 'trig-1'));
		$this->assertNull($this->trigger(objects: null, config: $full)->find(triggerId: 'trig-1'));
		$this->assertNull($this->trigger(objects: $objects, config: $full)->find(triggerId: ''));
	}//end testAnUnconfiguredStoreReadsNoTrigger()

	/**
	 * Without a configured schema the archive still runs, but no switch-off is written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreArchivesWithoutWritingTheTrigger(): void {
		$objects = new FakeObjectService();
		$this->decisions->expects($this->once())->method('archive')->with('besch-1')->willReturn([]);

		$outcome = $this->trigger(objects: $objects, config: ['register' => 'dossiq'])->process(trigger: $this->lapsed());

		$this->assertSame(expected: BezwaarArchiveTrigger::ARCHIVED, actual: $outcome);
		$this->assertSame(expected: [], actual: $objects->store);
	}//end testAnUnconfiguredStoreArchivesWithoutWritingTheTrigger()

	/**
	 * A refused switch-off is logged and does not undo the archive outcome.
	 *
	 * @return void
	 */
	public function testARefusedSwitchOffIsLoggedAndTheArchiveStands(): void {
		$objects = new class extends FakeObjectService {
			/**
			 * Refuse every write.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param ObjectSaveOptions    $options       The options.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('store down');
			}
		};
		$this->decisions->expects($this->once())->method('archive')->with('besch-1')->willReturn([]);
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq bezwaar: switching the trigger off failed', ['error' => 'store down']);

		$outcome = $this->trigger(objects: $objects, config: ['register' => 'dossiq', 'bezwaar_trigger_schema' => 'bezwaarTrigger'])
			->process(trigger: $this->lapsed());

		$this->assertSame(expected: BezwaarArchiveTrigger::ARCHIVED, actual: $outcome);
	}//end testARefusedSwitchOffIsLoggedAndTheArchiveStands()
}//end class
