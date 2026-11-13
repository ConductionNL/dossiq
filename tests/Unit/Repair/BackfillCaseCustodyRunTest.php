<?php

/**
 * The custody backfill opens one dated holding per case, and tells the operator what it did.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillCaseCustody;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\BackfillCaseCustody
 */
class BackfillCaseCustodyRunTest extends TestCase {

	/**
	 * What the step reported.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * Every begin() the chain received, as named arguments.
	 *
	 * @var list<array<string, string>>
	 */
	private array $begun = [];

	/**
	 * An output that records every info line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * A store that runs work as the system and answers findAll() with $rows.
	 *
	 * @param mixed $rows What findAll() returns.
	 *
	 * @return object The store.
	 */
	private function store(mixed $rows): object {
		return new class($rows) {
			/**
			 * The queries findAll() received.
			 *
			 * @var list<array>
			 */
			public array $queries = [];

			/**
			 * Whether work ran inside runAsSystem().
			 *
			 * @var bool
			 */
			public bool $asSystem = false;

			/**
			 * The store.
			 *
			 * @param mixed $rows What findAll() returns.
			 */
			public function __construct(private readonly mixed $rows) {
			}//end __construct()

			/**
			 * Run work as the system.
			 *
			 * @param callable $work The work.
			 *
			 * @return mixed What the work returns.
			 */
			public function runAsSystem(callable $work): mixed {
				$this->asSystem = true;
				return $work();
			}//end runAsSystem()

			/**
			 * The rows.
			 *
			 * @param array $config The query.
			 *
			 * @return mixed The rows.
			 */
			public function findAll(array $config): mixed {
				$this->queries[] = $config;
				return $this->rows;
			}//end findAll()
		};
	}//end store()

	/**
	 * A chain recording every begin() and answering with $answer per case id.
	 *
	 * @param array<string, array|null|\Throwable> $answers Per case id: the holding, null, or a throwable.
	 *
	 * @return CaseCustodyChain&MockObject The chain.
	 */
	private function chain(array $answers): CaseCustodyChain&MockObject {
		$chain = $this->createMock(CaseCustodyChain::class);
		$chain->method('begin')->willReturnCallback(
			function (string $caseId, string $organisationUnit, string $handler, string $from, string $reason, string $movedBy) use ($answers): ?array {
				$this->begun[] = compact('caseId', 'organisationUnit', 'handler', 'from', 'reason', 'movedBy');
				$answer = (array_key_exists($caseId, $answers) === true ? $answers[$caseId] : ['id' => 'h-' . $caseId]);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return $chain;
	}//end chain()

	/**
	 * The step, with register 'r', case schema 'c' and custody schema 'k' unless overridden.
	 *
	 * @param object|null           $store  The OpenRegister store.
	 * @param CaseCustodyChain      $chain  The chain.
	 * @param array<string, string> $config App config overrides.
	 * @param LoggerInterface|null  $logger The logger.
	 *
	 * @return BackfillCaseCustody The step.
	 */
	private function step(?object $store, CaseCustodyChain $chain, array $config=[], ?LoggerInterface $logger=null): BackfillCaseCustody {
		$config = array_merge(['register' => 'r', 'case_schema' => 'c', 'case_custody_schema' => 'k'], $config);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		return new BackfillCaseCustody(
			settingsService: $settings,
			custody: $chain,
			appConfig: $appConfig,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end step()

	/**
	 * Without OpenRegister nothing is read and the operator is told.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheBackfillIsSkipped(): void {
		$chain = $this->chain([]);
		$chain->expects($this->never())->method('begin');

		$this->step(null, $chain)->run($this->recordingOutput());

		self::assertSame(['OpenRegister unavailable, so the custody backfill was skipped.'], $this->said);
	}//end testWithoutOpenRegisterTheBackfillIsSkipped()

	/**
	 * A missing custody schema skips the backfill without reading a case.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCustodySchemaSkipsTheBackfill(): void {
		$store = $this->store([]);

		$this->step($store, $this->chain([]), ['case_custody_schema' => ''])->run($this->recordingOutput());

		self::assertSame([], $store->queries);
		self::assertSame(['The case or custody schema is not configured, so the custody backfill was skipped.'], $this->said);
	}//end testAnUnconfiguredCustodySchemaSkipsTheBackfill()

	/**
	 * Cases are read as the system from the configured register and schema, and tallied.
	 *
	 * @return void
	 */
	public function testEachCaseIsTalliedAsOpenedSkippedOrFailed(): void {
		$entity = new class {
			/**
			 * The case as OpenRegister serialises it.
			 *
			 * @return array The case.
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'u-2', 'assignedGroup' => ' team ', 'assignee' => ' piet ', 'registrationDate' => '2025-02-01'];
			}//end jsonSerialize()
		};
		$store = $this->store([
			['id' => 'c-1', 'startDate' => '2025-01-01', 'registrationDate' => '2024-12-01'],
			$entity,
			['id' => 'c-3'],
			['title' => 'no id'],
			['id' => 'c-5'],
		]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq custody backfill: a case did not get its first holding',
			['caseId' => 'c-5', 'exception' => 'chain down']
		);

		$this->step($store, $this->chain(['c-3' => null, 'c-5' => new RuntimeException('chain down')]), [], $logger)
			->run($this->recordingOutput());

		self::assertTrue($store->asSystem);
		self::assertSame([['filters' => ['register' => 'r', 'schema' => 'c']]], $store->queries);
		self::assertSame(['c-1', 'u-2', 'c-3', 'c-5'], array_column($this->begun, 'caseId'));
		self::assertSame(
			[
				'caseId' => 'u-2',
				'organisationUnit' => 'team',
				'handler' => 'piet',
				'from' => '2025-02-01',
				'reason' => BackfillCaseCustody::REASON,
				'movedBy' => '',
			],
			$this->begun[1]
		);
		self::assertSame('2025-01-01', $this->begun[0]['from']);
		self::assertSame(['Custody backfill: 2 holdings opened, 1 cases already had one, 2 failed.'], $this->said);
	}//end testEachCaseIsTalliedAsOpenedSkippedOrFailed()

	/**
	 * The holding is dated from the first date the case has, then from its metadata.
	 *
	 * @return void
	 */
	public function testTheHoldingFallsBackThroughTheCaseDates(): void {
		$store = $this->store([
			['id' => 'a', 'startDate' => ' ', 'requestedDate' => '2025-03-03', 'created' => '2025-04-04'],
			['id' => 'b', 'created' => '2025-04-04'],
			['id' => 'c', '@self' => ['created' => ' 2025-05-05 ']],
			['id' => 'd', '@self' => 'not an array'],
		]);

		$this->step($store, $this->chain([]))->run($this->recordingOutput());

		self::assertSame(['2025-03-03', '2025-04-04', '2025-05-05', ''], array_column($this->begun, 'from'));
	}//end testTheHoldingFallsBackThroughTheCaseDates()

	/**
	 * A store that answers with no list reads nothing and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseListIsReported(): void {
		$chain = $this->chain([]);
		$chain->expects($this->never())->method('begin');

		$this->step($this->store(null), $chain)->run($this->recordingOutput());

		self::assertSame(['Custody backfill: there was nothing to read.'], $this->said);
	}//end testAnUnreadableCaseListIsReported()
}//end class
