<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BeroepEscalationListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The dwingendStatus marker follows a live beroep onto its handled bezwaar,
 * and is left alone when the bezwaar cannot be found or reached.
 *
 * @covers \OCA\Dossiq\Listener\BeroepEscalationListener
 * @uses   \OCA\Dossiq\Listener\DwingendStatusRun
 * @uses   \OCA\Dossiq\Listener\Support\ObjectUpdatePayload
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BeroepEscalationListenerEdgeTest extends TestCase {

	private const BEROEP = [
		'sourceObjection' => 'bz-1',
		'contestedDecision' => 'bob-1',
		'appellantFilingDate' => '2026-03-10',
		'filingDeadline' => '2026-04-01',
	];

	/**
	 * @var InMemoryRegister The register.
	 */
	private InMemoryRegister $store;

	/**
	 * @var list<string> The debug lines logged.
	 */
	private array $debug = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->debug = [];
		$this->store->seed(schema: 'bezwaar', uuid: 'bz-1', row: ['status' => 'Handled', 'dwingendStatus' => false]);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testALiveBeroepFlagsItsHandledBezwaar(): void {
		$this->listener()->handle($this->created(self::BEROEP));

		$this->assertTrue($this->store->row(schema: 'bezwaar', uuid: 'bz-1')['dwingendStatus']);
		$this->assertSame(1, $this->store->writes);
	}//end testALiveBeroepFlagsItsHandledBezwaar()

	/**
	 * @return void
	 */
	public function testABeroepOnAnUnknownBezwaarWritesNothing(): void {
		$this->listener()->handle($this->created(array_merge(self::BEROEP, ['sourceObjection' => 'bz-404'])));

		$this->assertSame(0, $this->store->writes);
		$this->assertFalse($this->store->row(schema: 'bezwaar', uuid: 'bz-1')['dwingendStatus']);
	}//end testABeroepOnAnUnknownBezwaarWritesNothing()

	/**
	 * @return void
	 */
	public function testWithoutABezwaarSchemaNothingIsWritten(): void {
		$this->listener(config: ['register' => 'dossiq', 'beroep_schema' => 'beroep'])->handle($this->created(self::BEROEP));

		$this->assertSame(0, $this->store->writes);
	}//end testWithoutABezwaarSchemaNothingIsWritten()

	/**
	 * @return void
	 */
	public function testAFailingDerivationIsSwallowedAndLogged(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => (['beroep_schema' => 'beroep'][$key] ?? ''));
		$settings->method('getObjectService')->willThrowException(new RuntimeException('register down'));

		(new BeroepEscalationListener(settingsService: $settings, logger: $this->logger()))->handle($this->created(self::BEROEP));

		$this->assertSame(['Dossiq beroep: dwingendStatus derivation swallowed exception: register down'], $this->debug);
		$this->assertSame(0, $this->store->writes);
	}//end testAFailingDerivationIsSwallowedAndLogged()

	/**
	 * @param array<string, mixed> $beroep The beroep's fields.
	 *
	 * @return ObjectCreatedEvent A created beroep.
	 */
	private function created(array $beroep): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setObject($beroep);
		$entity->setSchema('beroep');
		$entity->setUuid('br-1');

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * @param array<string, string>|null $config The settings; all set when null.
	 *
	 * @return BeroepEscalationListener The listener over the store.
	 */
	private function listener(?array $config = null): BeroepEscalationListener {
		$config ??= ['register' => 'dossiq', 'beroep_schema' => 'beroep', 'bezwaar_schema' => 'bezwaar'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$settings->method('getObjectService')->willReturn($this->store);

		return new BeroepEscalationListener(settingsService: $settings, logger: $this->logger());
	}//end listener()

	/**
	 * @return LoggerInterface A logger that records debug lines.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		return $logger;
	}//end logger()
}//end class
