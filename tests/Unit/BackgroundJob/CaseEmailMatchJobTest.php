<?php

/**
 * The email-to-case match job: whether it runs, and for whom.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\CaseEmailMatchJob;
use OCA\Dossiq\Service\CaseEmailMatchService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for CaseEmailMatchJob.
 *
 * @covers \OCA\Dossiq\BackgroundJob\CaseEmailMatchJob
 */
class CaseEmailMatchJobTest extends TestCase {

	/**
	 * The matcher the job drives.
	 *
	 * @var CaseEmailMatchService&MockObject
	 */
	private CaseEmailMatchService $matcher;

	/**
	 * Set up a matcher mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->matcher = $this->createMock(CaseEmailMatchService::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return CaseEmailMatchJob The job that ran.
	 */
	private function runJob(): CaseEmailMatchJob {
		$job = new CaseEmailMatchJob(
			time: $this->createMock(ITimeFactory::class),
			matcher: $this->matcher,
			logger: new NullLogger()
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $job;
	}//end runJob()

	/**
	 * The job runs every five minutes.
	 *
	 * @return void
	 */
	public function testTheJobRunsEveryFiveMinutes(): void {
		$job = new CaseEmailMatchJob(
			time: $this->createMock(ITimeFactory::class),
			matcher: $this->matcher,
			logger: new NullLogger()
		);

		$this->assertSame(300, $job->getInterval());
	}//end testTheJobRunsEveryFiveMinutes()

	/**
	 * With the instance toggle off, no user is even looked up.
	 *
	 * @return void
	 */
	public function testTheInstanceToggleOffStopsTheJobBeforeAnyUser(): void {
		$this->matcher->method('isInstanceEnabled')->willReturn(false);
		$this->matcher->expects($this->never())->method('optedInUsers');
		$this->matcher->expects($this->never())->method('runForUser');

		$this->runJob();
	}//end testTheInstanceToggleOffStopsTheJobBeforeAnyUser()

	/**
	 * Only opted-in users are run, each once.
	 *
	 * @return void
	 */
	public function testOnlyOptedInUsersAreRun(): void {
		$this->matcher->method('isInstanceEnabled')->willReturn(true);
		$this->matcher->method('optedInUsers')->willReturn(['alice', 'carol']);

		$ran = [];
		$this->matcher->method('runForUser')->willReturnCallback(
			function (string $userId) use (&$ran): array {
				$ran[] = $userId;
				return ['linked' => 1, 'scanned' => 2];
			}
		);

		$this->runJob();

		$this->assertSame(['alice', 'carol'], $ran);
	}//end testOnlyOptedInUsersAreRun()

	/**
	 * One user's failure is recorded and the next user still runs.
	 *
	 * @return void
	 */
	public function testOneUsersFailureDoesNotStopTheNext(): void {
		$this->matcher->method('isInstanceEnabled')->willReturn(true);
		$this->matcher->method('optedInUsers')->willReturn(['alice', 'carol']);

		$ran = [];
		$this->matcher->method('runForUser')->willReturnCallback(
			function (string $userId) use (&$ran): array {
				$ran[] = $userId;
				if ($userId === 'alice') {
					throw new RuntimeException('mailbox broke');
				}

				return ['linked' => 0, 'scanned' => 1];
			}
		);
		$this->matcher->expects($this->once())
			->method('writeStatus')
			->with('alice', 0, 0, 'run_failed');

		$this->runJob();

		$this->assertSame(['alice', 'carol'], $ran);
	}//end testOneUsersFailureDoesNotStopTheNext()
}//end class
