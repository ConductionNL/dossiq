<?php

/**
 * Linking a resident's dossier to a case: the job requeues when the service account is away and refuses an incomplete argument.
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

use OCA\Dossiq\BackgroundJob\LinkCollectionToCaseJob;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for LinkCollectionToCaseJob.
 *
 * @covers \OCA\Dossiq\BackgroundJob\LinkCollectionToCaseJob
 */
class LinkCollectionToCaseJobTest extends TestCase {

	/**
	 * A complete argument.
	 *
	 * @var array<string, string>
	 */
	private const ARGUMENT = ['register' => 'zaken', 'caseId' => 'case-1', 'collectionId' => 'col-1', 'subjectRef' => 'bsn-1'];

	/**
	 * The job over the given collaborators.
	 *
	 * @param SettingsService          $settings The settings.
	 * @param IJobList                 $jobList  The job list.
	 * @param LoggerInterface          $logger   The logger.
	 * @param BackgroundServiceAccount $account  The service account.
	 *
	 * @return LinkCollectionToCaseJob The job.
	 */
	private function job(
		SettingsService $settings,
		IJobList $jobList,
		LoggerInterface $logger,
		BackgroundServiceAccount $account
	): LinkCollectionToCaseJob {
		return new LinkCollectionToCaseJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			settingsService: $settings,
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			jobList: $jobList,
			logger: $logger,
			serviceAccount: $account
		);
	}//end job()

	/**
	 * An unavailable service account puts the same argument back on the queue.
	 *
	 * @return void
	 */
	public function testAnUnavailableAccountRequeuesTheArgument(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())->method('add')->with(LinkCollectionToCaseJob::class, self::ARGUMENT);
		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->method('runAs')->willThrowException(exception: new ServiceAccountUnavailableException(message: 'no account'));

		$job = $this->job(settings: $settings, jobList: $jobList, logger: $this->createMock(originalClassName: LoggerInterface::class), account: $account);
		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, self::ARGUMENT);
	}//end testAnUnavailableAccountRequeuesTheArgument()

	/**
	 * The run links inside the service account; an incomplete argument is logged and not requeued.
	 *
	 * @return void
	 */
	public function testTheRunLinksInsideTheServiceAccount(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->never())->method('add');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('LinkCollectionToCaseJob: nothing to link', ['case' => 'case-1', 'collection' => 'col-1']);
		$account = $this->createMock(originalClassName: BackgroundServiceAccount::class);
		$account->expects($this->once())->method('runAs')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		$job = $this->job(settings: $settings, jobList: $jobList, logger: $logger, account: $account);
		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, self::ARGUMENT);
	}//end testTheRunLinksInsideTheServiceAccount()

	/**
	 * Every missing part of the argument stops the link before the dossier is read.
	 *
	 * @return array<string, array{0: string}> The missing key per case.
	 */
	public static function missingParts(): array {
		return [
			'register'   => ['register'],
			'case'       => ['caseId'],
			'collection' => ['collectionId'],
			'subject'    => ['subjectRef'],
		];
	}//end missingParts()

	/**
	 * An argument without one of its parts links nothing and says so.
	 *
	 * @param string $missing The key left out.
	 *
	 * @return void
	 *
	 * @dataProvider missingParts
	 */
	public function testAnIncompleteArgumentLinksNothing(string $missing): void {
		$objectService = new class {

			/**
			 * Calls made on the service.
			 *
			 * @var list<string>
			 */
			public array $calls = [];

			/**
			 * Record any call.
			 *
			 * @param string       $name      The method.
			 * @param array<mixed> $arguments The arguments.
			 *
			 * @return null Nothing.
			 */
			public function __call(string $name, array $arguments): mixed {
				$this->calls[] = $name;
				return null;
			}//end __call()
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$argument = self::ARGUMENT;
		unset($argument[$missing]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'LinkCollectionToCaseJob: nothing to link',
			['case' => ($argument['caseId'] ?? ''), 'collection' => ($argument['collectionId'] ?? '')]
		);

		$job = $this->job(
			settings: $settings,
			jobList: $this->createMock(originalClassName: IJobList::class),
			logger: $logger,
			account: $this->createMock(originalClassName: BackgroundServiceAccount::class)
		);
		$job->link(argument: $argument);

		$this->assertSame(expected: [], actual: $objectService->calls);
	}//end testAnIncompleteArgumentLinksNothing()
}//end class
