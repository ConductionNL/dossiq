<?php

/**
 * BerichtenboxReadStatusJob Unit Tests.
 *
 * The job polls Berichtenbox for the read status of every message it has sent.
 * It read the message id off each row with `$message['uuid']`, and those rows
 * come from `ObjectService::findAll()`, which returns `ObjectEntity` objects.
 * Indexing one is an Error, so the job would have died on its first message on
 * any instance that had sent one. It is not registered in `appinfo/info.xml`,
 * so it has never run, which is the only reason this was not an outage as
 * well: an unregistered job and a job that dies on its first row leave exactly
 * the same evidence, which is nothing in the log.
 *
 * The rows below are `ObjectEntity` objects for that reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\BerichtenboxReadStatusJob;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use stdClass;

/**
 * Unit tests for BerichtenboxReadStatusJob.
 *
 * @covers \OCA\Dossiq\BackgroundJob\BerichtenboxReadStatusJob
 *
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class BerichtenboxReadStatusJobTest extends TestCase {
	/**
	 * A stored message in the shape `findAll()` returns it.
	 *
	 * @param string $uuid The message uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function message(string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject(['status' => 'sent', 'externalMessageId' => 'bbx-' . $uuid]);

		return $row;
	}//end message()

	/**
	 * Build the job over a Berichtenbox service holding the given messages.
	 *
	 * @param array<int, mixed> $messages What getPendingMessages() returns.
	 *
	 * @return array{0: BerichtenboxReadStatusJob, 1: BerichtenboxService} The job and its service.
	 */
	private function job(array $messages): array {
		$berichtenbox = $this->createMock(BerichtenboxService::class);
		$berichtenbox->method('getPendingMessages')->willReturn($messages);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$job = new BerichtenboxReadStatusJob(
			$this->createMock(ITimeFactory::class),
			$berichtenbox,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);

		return [$job, $berichtenbox];
	}//end job()

	/**
	 * Invoke the protected run() method.
	 *
	 * @param BerichtenboxReadStatusJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(BerichtenboxReadStatusJob $job): void {
		$method = new ReflectionMethod(BerichtenboxReadStatusJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Every pending message is polled, by the uuid on its own row.
	 *
	 * @return void
	 */
	public function testEveryPendingMessageIsPolledByItsUuid(): void {
		$polled = [];
		[$job, $berichtenbox] = $this->job([$this->message('msg-1'), $this->message('msg-2')]);
		$berichtenbox->method('pollReadStatus')->willReturnCallback(
			static function (string $messageId) use (&$polled): array {
				$polled[] = $messageId;

				return [];
			}
		);

		$this->runJob($job);

		$this->assertSame(['msg-1', 'msg-2'], $polled);
	}//end testEveryPendingMessageIsPolledByItsUuid()

	/**
	 * A row that carries no id is skipped, and the rest are still polled.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdIsSkippedAndTheRestArePolled(): void {
		$polled = [];
		[$job, $berichtenbox] = $this->job([new stdClass(), $this->message('msg-2')]);
		$berichtenbox->method('pollReadStatus')->willReturnCallback(
			static function (string $messageId) use (&$polled): array {
				$polled[] = $messageId;

				return [];
			}
		);

		$this->runJob($job);

		$this->assertSame(['msg-2'], $polled);
	}//end testARowWithoutAnIdIsSkippedAndTheRestArePolled()
}//end class
