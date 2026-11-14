<?php

/**
 * FlowTimerEngine — arming and cancelling on OpenRegister's flow timer engine.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\FlowTimerEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The engine wrapper, against a recording engine.
 *
 * @covers \OCA\Dossiq\Service\Support\FlowTimerEngine
 */
final class FlowTimerEngineTest extends TestCase {

	/**
	 * A wrapper over the given engine and logger.
	 *
	 * @param object|null     $engine The engine, or null when absent.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return FlowTimerEngine
	 */
	private function timers(?object $engine, LoggerInterface $logger): FlowTimerEngine {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(FlowTimerEngine::ENGINE_CLASS)->willReturn($engine);

		return new FlowTimerEngine(settingsService: $settings, logger: $logger, owner: 'Dossiq test', timer: 'test timer');
	}//end timers()

	/**
	 * A recording engine that refuses when told to.
	 *
	 * @param bool $refuse Whether every call throws.
	 *
	 * @return object
	 */
	private function engine(bool $refuse): object {
		return new class($refuse) {
			/**
			 * The calls received.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param bool $refuse Whether every call throws.
			 */
			public function __construct(private readonly bool $refuse) {
			}//end __construct()

			/**
			 * Record an arm.
			 *
			 * @param array<string, mixed> $config The config.
			 * @param mixed                $actor  The actor.
			 *
			 * @return void
			 */
			public function arm(array $config, mixed $actor): void {
				$this->calls[] = 'arm:'.$config['title'];
				if ($this->refuse === true) {
					throw new \RuntimeException('no');
				}
			}//end arm()

			/**
			 * Record a cancel.
			 *
			 * @param object $subject The subject.
			 * @param object $change  The change.
			 *
			 * @return void
			 */
			public function cancelForSubject(object $subject, object $change): void {
				$this->calls[] = 'cancel';
				if ($this->refuse === true) {
					throw new \RuntimeException('no');
				}
			}//end cancelForSubject()
		};
	}//end engine()

	/**
	 * Without an engine nothing is built or called and the outcome is unavailable.
	 *
	 * @return void
	 */
	public function testAbsentEngineIsUnavailable(): void {
		$timers = $this->timers(engine: null, logger: $this->createMock(LoggerInterface::class));
		$built = false;

		self::assertSame(FlowTimerEngine::UNAVAILABLE, $timers->arm(config: function () use (&$built): array {
			$built = true;
			return [];
		}, logContext: ['case' => 'c1']));
		self::assertFalse($built);
		self::assertSame(FlowTimerEngine::UNAVAILABLE, $timers->cancel(subjectUuid: 'c1', reason: 'r', logContext: ['case' => 'c1']));
	}//end testAbsentEngineIsUnavailable()

	/**
	 * A willing engine arms and cancels.
	 *
	 * @return void
	 */
	public function testWillingEngineArmsAndCancels(): void {
		$engine = $this->engine(refuse: false);
		$timers = $this->timers(engine: $engine, logger: $this->createMock(LoggerInterface::class));

		self::assertSame(FlowTimerEngine::ARMED, $timers->arm(config: static fn (): array => ['title' => 'T'], logContext: ['case' => 'c1']));
		self::assertNull($timers->cancel(subjectUuid: 'c1', reason: 'r', logContext: ['case' => 'c1']));
		self::assertSame(['arm:T', 'cancel'], $engine->calls);
	}//end testWillingEngineArmsAndCancels()

	/**
	 * A refusal is logged with the subject and read as unavailable.
	 *
	 * @return void
	 */
	public function testRefusalIsLoggedAndUnavailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			static function (string $message, array $context): void {
				self::assertContains($message, ['Dossiq test: the engine refused the test timer', 'Dossiq test: the engine refused to cancel the test timer']);
				self::assertSame(['case' => 'c1', 'error' => 'no'], $context);
			}
		);
		$timers = $this->timers(engine: $this->engine(refuse: true), logger: $logger);

		self::assertSame(FlowTimerEngine::UNAVAILABLE, $timers->arm(config: static fn (): array => ['title' => 'T'], logContext: ['case' => 'c1']));
		self::assertSame(FlowTimerEngine::UNAVAILABLE, $timers->cancel(subjectUuid: 'c1', reason: 'r', logContext: ['case' => 'c1']));
	}//end testRefusalIsLoggedAndUnavailable()
}//end class
