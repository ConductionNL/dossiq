<?php

/**
 * Golden record of the queued deadline notification.
 *
 * Records, per argument, what the job asks the notification service to send
 * and every log line, so a refactor cannot change which queued notifications
 * are sent or refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\DeadlineNotificationDispatchJob;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for DeadlineNotificationDispatchJob::run.
 *
 * @covers \OCA\Dossiq\BackgroundJob\DeadlineNotificationDispatchJob
 */
class DeadlineNotificationDispatchJobGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current run.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every argument the job distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testRunAcrossArguments(): void {
		$full = ['type' => 'warning', 'termijnInstanceId' => 'ti-1', 'recipientUserId' => 'alice', 'context' => ['days' => 3]];
		$worlds = [
			'not an array' => ['ti-1', false],
			'no type' => [['termijnInstanceId' => 'ti-1', 'recipientUserId' => 'alice'], false],
			'no instance' => [['type' => 'warning', 'recipientUserId' => 'alice'], false],
			'no recipient' => [['type' => 'warning', 'termijnInstanceId' => 'ti-1'], false],
			'sent' => [$full, false],
			'sent without context' => [['type' => 'expired', 'termijnInstanceId' => 'ti-2', 'recipientUserId' => 'bob'], false],
			'sending fails' => [$full, true],
		];

		$record = [];
		foreach ($worlds as $name => [$argument, $fails]) {
			$this->events = [];
			$run = new ReflectionMethod(DeadlineNotificationDispatchJob::class, 'run');
			$run->setAccessible(true);
			$run->invoke($this->jobFor(fails: $fails), $argument);
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'deadline-notification-dispatch', value: $record);
	}//end testRunAcrossArguments()

	/**
	 * A job whose notification service fails or not.
	 *
	 * @param bool $fails Whether sending fails.
	 *
	 * @return DeadlineNotificationDispatchJob
	 */
	private function jobFor(bool $fails): DeadlineNotificationDispatchJob {
		$service = $this->createMock(TermijnNotificationService::class);
		$service->method('sendTermijnNotification')->willReturnCallback(
			function (...$args) use ($fails): array {
				$this->events[] = 'send ' . json_encode($args);
				if ($fails === true) {
					throw new RuntimeException('mailer down');
				}

				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new DeadlineNotificationDispatchJob(
			time: $this->createMock(ITimeFactory::class),
			notificationService: $service,
			logger: $logger,
		);
	}//end jobFor()
}//end class
