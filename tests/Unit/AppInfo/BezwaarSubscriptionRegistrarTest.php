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
 * Without OpenRegister's filtered subscription, the objection lifecycle and
 * legal-hold listeners still subscribe, unfiltered, and each fallback says so
 * in the log.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BezwaarSubscriptionRegistrar
 */
class BezwaarSubscriptionRegistrarTest extends TestCase {

	/**
	 * The server container before the test.
	 *
	 * @var mixed
	 */
	private $server;

	/**
	 * Keep the server container to put it back.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->server = \OC::$server;
	}//end setUp()

	/**
	 * Put the server container back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\OC::$server = $this->server;
		parent::tearDown();
	}//end tearDown()

	public function testWithoutTheFilteredSubscriptionEachListenerFallsBackLoudly(): void {
		self::assertFalse(class_exists('OCA\\OpenRegister\\Event\\ObjectEventSubscription'), 'the filtered subscription is absent in this test run');

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		\OC::$server = new class ($logger) {
			/**
			 * @param LoggerInterface $logger The logger the registrar asks for.
			 */
			public function __construct(private LoggerInterface $logger) {
			}//end __construct()

			/**
			 * Resolve the logger, and nothing else.
			 *
			 * @param string $name The service.
			 *
			 * @return object
			 */
			public function get(string $name): object {
				if ($name !== LoggerInterface::class) {
					throw new \RuntimeException('Unexpected service '.$name);
				}

				return $this->logger;
			}//end get()
		};

		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::exactly(3))->method('addServiceListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new BezwaarSubscriptionRegistrar())->subscribe(dispatcher: $dispatcher);

		self::assertSame(
			[
				[ObjectCreatedEvent::class, BezwaarLifecycleListener::class],
				[ObjectUpdatedEvent::class, BezwaarLifecycleListener::class],
				[ObjectCreatedEvent::class, BezwaarLegalHoldListener::class],
			],
			$subscribed
		);
		self::assertCount(3, $warnings);
		self::assertSame(
			'OpenRegister ObjectEventSubscription unavailable: '.BezwaarLegalHoldListener::class
			.' fell back to an UNFILTERED registration for '.ObjectCreatedEvent::class
			.' and will be invoked on every object write instance-wide.',
			$warnings[2][0]
		);
		self::assertSame(['app' => 'dossiq'], $warnings[0][1]);
		self::assertStringContainsString(ObjectUpdatedEvent::class, $warnings[1][0]);
	}//end testWithoutTheFilteredSubscriptionEachListenerFallsBackLoudly()
}//end class
