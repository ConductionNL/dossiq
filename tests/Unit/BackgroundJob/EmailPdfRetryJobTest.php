<?php

/**
 * Which archivals the PDF retry job re-marks, and when it does nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\EmailPdfRetryJob;
use OCA\Dossiq\Service\EmailArchivalService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for EmailPdfRetryJob.
 *
 * @covers \OCA\Dossiq\BackgroundJob\EmailPdfRetryJob
 */
class EmailPdfRetryJobTest extends TestCase {

	/**
	 * The archival service.
	 *
	 * @var EmailArchivalService&MockObject
	 */
	private EmailArchivalService $archival;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Create the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->archival = $this->createMock(originalClassName: EmailArchivalService::class);
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once with the given installed apps.
	 *
	 * @param list<string> $apps The installed apps.
	 *
	 * @return void
	 */
	private function runWith(array $apps): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);
		$job = new EmailPdfRetryJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			appManager: $appManager,
			archivalService: $this->archival,
			logger: $this->logger
		);

		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, null);
	}//end runWith()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$this->archival->expects($this->never())->method('listFailedArchivals');
		$this->archival->expects($this->never())->method('markFailed');

		$this->runWith(apps: ['files', 'dossiq']);
	}//end testWithoutOpenRegisterNothingHappens()

	/**
	 * Up to 25 failed archivals are re-marked as failed; a row without an id is skipped.
	 *
	 * @return void
	 */
	public function testFailedArchivalsAreReMarked(): void {
		$this->archival->expects($this->once())->method('listFailedArchivals')->with(25)->willReturn(
			[['archivalId' => 'a-1'], ['archivalId' => ''], ['subject' => 'no id'], ['archivalId' => 'a-2']]
		);
		$marked = [];
		$this->archival->expects($this->exactly(count: 2))->method('markFailed')->willReturnCallback(
			static function (string $archivalId, string $errorMessage) use (&$marked): bool {
				$marked[] = [$archivalId, $errorMessage];
				return true;
			}
		);
		$this->logger->expects($this->never())->method('error');

		$this->runWith(apps: ['openregister', 'dossiq']);

		$this->assertSame(
			expected: [['a-1', 'retry pending Docudesk adapter wiring'], ['a-2', 'retry pending Docudesk adapter wiring']],
			actual: $marked
		);
	}//end testFailedArchivalsAreReMarked()

	/**
	 * A failure is logged with the app id and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailureIsLogged(): void {
		$this->archival->method('listFailedArchivals')->willThrowException(exception: new RuntimeException(message: 'table missing'));
		$this->logger->expects($this->once())->method('error')->with('EmailPdfRetryJob failed', ['error' => 'table missing', 'app' => 'dossiq']);

		$this->runWith(apps: ['openregister']);
	}//end testAFailureIsLogged()
}//end class
