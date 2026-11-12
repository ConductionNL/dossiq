<?php

/**
 * Golden record of the queued acknowledgement of receipt.
 *
 * Records, per argument and outcome, what the job delivers, records, requeues
 * and logs, so a refactor cannot change when an acknowledgement is retried.
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

use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for AcknowledgementDispatchJob::work.
 *
 * @covers \OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob
 */
class AcknowledgementDispatchJobGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the job distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testDispatchAcrossWorlds(): void {
		$worlds = [
			'not an array' => ['case-1', 'ok', true],
			'no case' => [['attempt' => 2], 'ok', true],
			'delivered, first attempt' => [['caseId' => 'case-1'], 'ok', true],
			'delivered, attempt below one' => [['caseId' => 'case-1', 'attempt' => -3], 'ok', true],
			'refused, retried' => [['caseId' => 'case-1', 'attempt' => 2], 'refuse', true],
			'refused, given up' => [['caseId' => 'case-1', 'attempt' => 5], 'refuse', false],
			'broke, retried' => [['caseId' => 'case-1', 'attempt' => 1], 'throw', true],
		];

		$record = [];
		foreach ($worlds as $name => [$argument, $outcome, $again]) {
			$this->events = [];
			$work = new ReflectionMethod(AcknowledgementDispatchJob::class, 'work');
			$work->setAccessible(true);
			$work->invoke($this->jobFor(outcome: $outcome, again: $again), $argument);
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'acknowledgement-dispatch', value: $record);
	}//end testDispatchAcrossWorlds()

	/**
	 * A job whose delivery has the given outcome.
	 *
	 * @param string $outcome 'ok', 'refuse' or 'throw'.
	 * @param bool   $again   Whether a failed attempt is retried.
	 *
	 * @return AcknowledgementDispatchJob
	 */
	private function jobFor(string $outcome, bool $again): AcknowledgementDispatchJob {
		$service = $this->createMock(AcknowledgementService::class);
		$service->method('acknowledge')->willReturnCallback(
			function (string $caseId, int $attempt) use ($outcome): array {
				$this->events[] = 'acknowledge ' . $caseId . ' attempt ' . $attempt;
				if ($outcome === 'refuse') {
					throw new RefusedException('ack-no-address', 'The applicant has no address.');
				}

				if ($outcome === 'throw') {
					throw new RuntimeException('mailer down');
				}

				return ['sent' => true];
			}
		);
		$service->method('recordFailedAttempt')->willReturnCallback(
			function (string $caseId, string $sentence, int $attempt) use ($again): bool {
				$this->events[] = 'failed ' . $caseId . ' attempt ' . $attempt . ': ' . $sentence;
				return $again;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->events[] = 'requeue ' . json_encode($argument);
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

		return new AcknowledgementDispatchJob(
			time: $this->createMock(ITimeFactory::class),
			acknowledgement: $service,
			jobList: $jobs,
			logger: $logger,
			serviceAccount: $this->createMock(BackgroundServiceAccount::class),
		);
	}//end jobFor()
}//end class
