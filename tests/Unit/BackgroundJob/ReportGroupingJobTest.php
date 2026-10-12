<?php

/**
 * Unit tests for ReportGroupingJob: the run places the case as the background
 * service account, and without an account it reads, sends and writes nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\ReportGroupingJob;
use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\BackgroundJob\ReportGroupingJob
 *
 * @uses \OCA\Dossiq\Service\Ai\ReportGroupingConsumer
 * @uses \OCA\Dossiq\Service\Ai\CaseTypeAiFeatures
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class ReportGroupingJobTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many times hermiq was asked.
	 *
	 * @var int
	 */
	private int $asked = 0;

	/**
	 * Every job the run queued: [class, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Seed one case whose case type groups reports.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->asked = 0;
		$this->queued = [];
		$this->register->seed(
			schema: 'caseType',
			id: 'ct-1',
			row: ['aiFeatures' => [ReportGroupingConsumer::FEATURE_SLUG => 'case']]
		);
		$this->register->seed(schema: 'case', id: 'case-1', row: ['caseType' => 'ct-1', 'title' => 'Stroomstoring']);
	}//end setUp()

	/**
	 * The run places the case and writes the group id as the service account.
	 *
	 * @return void
	 */
	public function testTheRunPlacesTheCaseAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job(), argument: ['caseId' => 'case-1']);

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		self::assertSame(expected: 'g-1', actual: $this->register->row(schema: 'case', id: 'case-1')['reportGroupId']);
		self::assertSame(expected: 1, actual: $this->asked);
	}//end testTheRunPlacesTheCaseAsTheServiceAccount()

	/**
	 * Without an account nothing is asked or written, and the argument is queued again.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountNothingIsAskedOrWritten(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job(), argument: ['caseId' => 'case-1']);

		self::assertSame(expected: [], actual: $this->register->writes);
		self::assertSame(expected: 0, actual: $this->asked);
		self::assertSame(expected: [[ReportGroupingJob::class, ['caseId' => 'case-1']]], actual: $this->queued);
	}//end testWithoutAnAccountNothingIsAskedOrWritten()

	/**
	 * A job naming no case does nothing.
	 *
	 * @return void
	 */
	public function testAJobNamingNoCaseDoesNothing(): void {
		$this->runJobOnce(job: $this->job(), argument: []);

		self::assertSame(expected: [], actual: $this->register->writes);
		self::assertSame(expected: 0, actual: $this->asked);
	}//end testAJobNamingNoCaseDoesNothing()

	/**
	 * The real job over the real consumer.
	 *
	 * @return ReportGroupingJob The job.
	 */
	private function job(): ReportGroupingJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				default => $default,
			}
		);

		$client = $this->createMock(HermiqAiFeatureClient::class);
		$client->method('groupFor')->willReturnCallback(
			function (): array {
				$this->asked++;
				return ['groupId' => 'g-1', 'count' => 1, 'nearDuplicates' => [], 'newGroup' => true];
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				$this->queued[] = [$job, $argument];
			}
		);

		$grouping = new ReportGroupingConsumer(
			aiFeatures: new CaseTypeAiFeatures(),
			client: $client,
			caseTypes: new CaseTypeStore(settingsService: $settings),
			settingsService: $settings,
			logger: new NullLogger(),
		);

		return $this->buildWith(
			ReportGroupingJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'grouping' => $grouping,
				'jobList' => $jobList,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
