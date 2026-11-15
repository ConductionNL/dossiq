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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bezwaar lifecycle and legal hold listeners subscribe narrowed to the
 * dossiq register and their own schemas when OpenRegister offers a filtered
 * subscription, and fall back to an unfiltered listener with a warning when
 * it does not.
 *
 * Both paths define stand-ins for classes the test bootstrap does not load
 * (OpenRegister's ObjectEventSubscription, Nextcloud's server), so each runs
 * in its own process and leaks nothing into other tests.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BezwaarSubscriptionRegistrar
 */
class BezwaarSubscriptionRegistrarTest extends TestCase {

	private const LIFECYCLE = ['objectionProceeding', 'objection', 'hearingSession', 'advisoryReport', 'decision'];
	private const LEGAL_HOLD = ['objection', 'objectionProceeding', 'beroep', 'bezwaarDecision', 'appealDecision'];

	#[RunInSeparateProcess]
	public function testEachListenerSubscribesNarrowedToItsSchemas(): void {
		eval(<<<'PHP'
			namespace OCA\OpenRegister\Event;
			final class ObjectEventSubscription {
				public static array $calls = [];
				public static function subscribe(\OCP\EventDispatcher\IEventDispatcher $dispatcher, string $event, string $listener, array $registers, array $schemas): void {
					self::$calls[] = [$event, $listener, $registers, $schemas];
				}
			}
			PHP);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('addServiceListener');

		(new BezwaarSubscriptionRegistrar())->subscribe(dispatcher: $dispatcher);

		self::assertSame(
			[
				[ObjectCreatedEvent::class, BezwaarLifecycleListener::class, ['dossiq'], self::LIFECYCLE],
				[ObjectUpdatedEvent::class, BezwaarLifecycleListener::class, ['dossiq'], self::LIFECYCLE],
				[ObjectCreatedEvent::class, BezwaarLegalHoldListener::class, ['dossiq'], self::LEGAL_HOLD],
			],
			\OCA\OpenRegister\Event\ObjectEventSubscription::$calls
		);
	}//end testEachListenerSubscribesNarrowedToItsSchemas()

	#[RunInSeparateProcess]
	public function testWithoutAFilteredSubscriptionEachListenerFallsBackLoudly(): void {
		self::assertFalse(class_exists('\\OCA\\OpenRegister\\Event\\ObjectEventSubscription'), 'the fallback path needs OpenRegister without filtered subscriptions');

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context=[]) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		if (class_exists('\\OC', false) === false) {
			eval('final class OC { public static $server; }');
		}

		\OC::$server = new class($logger) {
			public function __construct(private LoggerInterface $logger) {
			}

			public function get(string $name): mixed {
				return $name === LoggerInterface::class ? $this->logger : null;
			}
		};

		$added = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$added): void {
				$added[] = [$event, $listener];
			}
		);

		(new BezwaarSubscriptionRegistrar())->subscribe(dispatcher: $dispatcher);

		self::assertSame(
			[
				[ObjectCreatedEvent::class, BezwaarLifecycleListener::class],
				[ObjectUpdatedEvent::class, BezwaarLifecycleListener::class],
				[ObjectCreatedEvent::class, BezwaarLegalHoldListener::class],
			],
			$added
		);
		self::assertCount(3, $warnings);
		self::assertSame(
			'OpenRegister ObjectEventSubscription unavailable: ' . BezwaarLegalHoldListener::class
			. ' fell back to an UNFILTERED registration for ' . ObjectCreatedEvent::class
			. ' and will be invoked on every object write instance-wide.',
			$warnings[2][0]
		);
		self::assertSame(['app' => 'dossiq'], $warnings[0][1]);
	}//end testWithoutAFilteredSubscriptionEachListenerFallsBackLoudly()
}//end class
