<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\BezwaarLegalHoldListener;
use OCA\Dossiq\Listener\BezwaarLifecycleListener;
use OCA\Dossiq\Listener\Registrar\BezwaarSubscriptionRegistrar;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Without OpenRegister's filtered subscription the objection listeners still
 * register, unfiltered, and each fallback says so in the log.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BezwaarSubscriptionRegistrar
 */
class BezwaarSubscriptionRegistrarTest extends TestCase {

	/**
	 * The server container before the test swapped it.
	 *
	 * @var mixed
	 */
	private mixed $server = null;

	protected function setUp(): void {
		parent::setUp();
		$this->server = \OC::$server;
	}//end setUp()

	protected function tearDown(): void {
		\OC::$server = $this->server;
		parent::tearDown();
	}//end tearDown()

	public function testWithoutTheFilteredSubscriptionEveryListenerFallsBackLoudly(): void {
		self::assertFalse(
			class_exists('\\OCA\\OpenRegister\\Event\\ObjectEventSubscription'),
			'This test covers the fallback; the suite does not stub the filtered subscription.'
		);

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		\OC::$server = new class ($logger) {
			public function __construct(private readonly LoggerInterface $logger) {
			}

			public function get(string $name): object {
				if ($name !== LoggerInterface::class) {
					throw new \RuntimeException('Unexpected service ' . $name);
				}

				return $this->logger;
			}
		};

		$added = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$added): void {
				$added[] = [$event, $listener, $priority];
			}
		);

		(new BezwaarSubscriptionRegistrar())->subscribe(dispatcher: $dispatcher);

		self::assertSame(
			[
				[ObjectCreatedEvent::class, BezwaarLifecycleListener::class, 0],
				[ObjectUpdatedEvent::class, BezwaarLifecycleListener::class, 0],
				[ObjectCreatedEvent::class, BezwaarLegalHoldListener::class, 0],
			],
			$added
		);
		self::assertCount(3, $warnings, 'Each unfiltered registration is announced, not silent.');
		self::assertSame(['app' => 'dossiq'], $warnings[0][1]);
		self::assertSame(
			'OpenRegister ObjectEventSubscription unavailable: ' . BezwaarLegalHoldListener::class
			. ' fell back to an UNFILTERED registration for ' . ObjectCreatedEvent::class
			. ' and will be invoked on every object write instance-wide.',
			$warnings[2][0]
		);
	}//end testWithoutTheFilteredSubscriptionEveryListenerFallsBackLoudly()
}//end class
