<?php

/**
 * Unit tests for what ReportGroupingJob does with each answer: hermiq refusing,
 * the case write failing, and a case that is already placed.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\ReportGroupingJob;
use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A refused or failed placement is logged with the case it was about, and
 * never escapes the job.
 *
 * @covers \OCA\Dossiq\BackgroundJob\ReportGroupingJob
 * @uses \OCA\Dossiq\Service\Ai\ReportGroupingConsumer
 * @uses \OCA\Dossiq\Service\Ai\CaseTypeAiFeatures
 * @uses \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class ReportGroupingJobOutcomesTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The log lines the job wrote: [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * How many times hermiq was asked.
	 *
	 * @var int
	 */
	private int $asked = 0;

	/**
	 * A register whose case writes fail.
	 *
	 * @return AnonymousRefusingRegister The register.
	 */
	private function failingRegister(): AnonymousRefusingRegister {
		return new class(actor: fn (): ?string => $this->actingUid()) extends AnonymousRefusingRegister {
			/**
			 * Refuse every write.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param array<int, string>|null $extend Ignored.
			 * @param string|int|null $register Ignored.
			 * @param string|int|null $schema Ignored.
			 * @param string|null $uuid Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				throw new RuntimeException('The store is read-only');
			}
		};
	}

	/**
	 * Seed one case of a case type that groups reports.
	 *
	 * @param AnonymousRefusingRegister $register The register.
	 * @param array<string, mixed> $case Extra case fields.
	 *
	 * @return AnonymousRefusingRegister The seeded register.
	 */
	private function seeded(AnonymousRefusingRegister $register, array $case = []): AnonymousRefusingRegister {
		$register->seed(
			schema: 'caseType',
			id: 'ct-1',
			row: ['aiFeatures' => [ReportGroupingConsumer::FEATURE_SLUG => 'case']]
		);
		$register->seed(schema: 'case', id: 'case-1', row: (['caseType' => 'ct-1', 'title' => 'Stroomstoring'] + $case));

		return $register;
	}

	/**
	 * The job over the real consumer, with hermiq answering as given.
	 *
	 * @param AnonymousRefusingRegister $register The register.
	 * @param callable(): array<string, mixed> $hermiq What hermiq answers, or throws.
	 *
	 * @return ReportGroupingJob The job.
	 */
	private function job(AnonymousRefusingRegister $register, callable $hermiq): ReportGroupingJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
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
			function () use ($hermiq): array {
				$this->asked++;
				return $hermiq();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return $this->buildWith(
			ReportGroupingJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'grouping' => new ReportGroupingConsumer(
					aiFeatures: new CaseTypeAiFeatures(),
					client: $client,
					caseTypes: new CaseTypeStore(settingsService: $settings),
					settingsService: $settings,
					logger: new NullLogger(),
				),
				'jobList' => $this->createMock(IJobList::class),
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}

	/**
	 * Hermiq refusing is a warning naming the case and hermiq's reason, and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testAHermiqRefusalIsAWarningNamingTheCase(): void {
		$register = $this->seeded($this->refusingRegister());
		$job = $this->job(
			$register,
			static function (): array {
				throw new HermiqAssistantException('Hermiq is not reachable', 503, 'hermiq-unavailable');
			}
		);

		$this->runJobOnce(job: $job, argument: ['caseId' => 'case-1']);

		self::assertSame(1, $this->asked);
		self::assertSame([], $register->writes);
		self::assertSame(
			[['warning', 'Dossiq report grouping: hermiq did not group case {case}: {reason}', ['case' => 'case-1', 'reason' => 'Hermiq is not reachable']]],
			$this->logged
		);
		self::assertNull($this->acting, 'The job left the service account signed in.');
	}

	/**
	 * A failing case write is an error naming the case, and does not escape the job.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsAnErrorNamingTheCase(): void {
		$job = $this->job(
			$this->seeded($this->failingRegister()),
			static fn (): array => ['groupId' => 'g-1', 'count' => 1, 'nearDuplicates' => [], 'newGroup' => true]
		);

		$this->runJobOnce(job: $job, argument: ['caseId' => 'case-1']);

		self::assertSame(
			[['error', 'Dossiq report grouping: placing case {case} threw', ['case' => 'case-1', 'reason' => 'The store is read-only']]],
			$this->logged
		);
		self::assertNull($this->acting, 'The job left the service account signed in.');
	}

	/**
	 * A case already in a group is not asked about again, and nothing is logged.
	 *
	 * @return void
	 */
	public function testAPlacedCaseIsLeftAlone(): void {
		$register = $this->seeded($this->refusingRegister(), ['reportGroupId' => 'g-7']);
		$job = $this->job(
			$register,
			static fn (): array => ['groupId' => 'g-1', 'count' => 1, 'nearDuplicates' => [], 'newGroup' => true]
		);

		$this->runJobOnce(job: $job, argument: ['caseId' => ' case-1 ']);

		self::assertSame(0, $this->asked);
		self::assertSame([], $register->writes);
		self::assertSame([], $this->logged);
	}
}
