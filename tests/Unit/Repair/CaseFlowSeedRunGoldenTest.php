<?php

/**
 * CaseFlowSeedDataRepairStep run golden tests.
 *
 * Pins what a run says and records: the three skip warnings, the summary
 * line with its counts, the failure warning and log line of a case type that
 * fails, and the seeded-cases marker written only after a complete run.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseFlowSeedDataRepairStep;
use OCA\Dossiq\Repair\CaseFlowSeedIndex;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for CaseFlowSeedDataRepairStep::run().
 *
 * @covers \OCA\Dossiq\Repair\CaseFlowSeedDataRepairStep
 * @uses \OCA\Dossiq\Repair\CaseFlowSeedFile
 * @uses \OCA\Dossiq\Repair\CaseFlowSeedIndex
 * @uses \OCA\Dossiq\Repair\CaseFlowSeedPhaseBackfill
 * @uses \OCA\Dossiq\Repair\CaseFlowSeedRun
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseFlowSeedRunGoldenTest extends TestCase {

	/**
	 * The app config the step reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $appConfig = ['demo_data_decided' => 'installed'];

	/**
	 * Saves by schema.
	 *
	 * @var array<string, int>
	 */
	private array $saves = [];

	/**
	 * What the output and the logger were told, in order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $said = [];

	/**
	 * A store that counts saves and fails on one schema.
	 *
	 * @param string|null $failsOnSchema The schema whose saves throw.
	 *
	 * @return object
	 */
	private function store(?string $failsOnSchema = null): object {
		return new class ($this->saves, $failsOnSchema) {
			private int $n = 0;

			public function __construct(public array &$saves, private ?string $failsOnSchema) {
			}

			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				return $data + ['id' => $objectId];
			}

			public function saveObject(array $object, string $register, string $schema): array {
				if ($schema === $this->failsOnSchema) {
					throw new RuntimeException('schema rejected the object');
				}

				$this->saves[$schema] = ($this->saves[$schema] ?? 0) + 1;
				$this->n++;

				return $object + ['id' => $schema . '-' . $this->n];
			}
		};
	}//end store()

	/**
	 * The step over the store.
	 *
	 * @param object|null $store         The store, or null for none.
	 * @param bool        $available     Whether OpenRegister reports itself available.
	 * @param bool        $configured    Whether the register and schemas are configured.
	 *
	 * @return CaseFlowSeedDataRepairStep
	 */
	private function step(?object $store, bool $available = true, bool $configured = true): CaseFlowSeedDataRepairStep {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => $this->appConfig[$key] ?? ($configured === true ? $key : '')
		);
		$settings->method('setConfigValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->appConfig[$key] = $value;
			}
		);

		$index = $this->getMockBuilder(CaseFlowSeedIndex::class)
			->setConstructorArgs([$settings])
			->onlyMethods(['caseTypeBySlug', 'caseTypeByTitle', 'caseTitlesFor'])
			->getMock();
		$index->method('caseTypeBySlug')->willReturn(null);
		$index->method('caseTypeByTitle')->willReturn(null);
		$index->method('caseTitlesFor')->willReturn([]);

		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('statusesOf')->willReturn([]);
		$lookup->method('rowsOf')->willReturn([]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->said[] = ['log', $message . ' ' . json_encode($context)];
			}
		);

		return new CaseFlowSeedDataRepairStep($settings, $lookup, $index, $logger);
	}//end step()

	/**
	 * An output that records what it is told.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->said[] = [$level, $message];
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * Each skip says why, and writes nothing.
	 *
	 * @return void
	 */
	public function testEachSkipSaysWhy(): void {
		$this->step($this->store(), available: false)->run($this->recordingOutput());
		$this->step(null)->run($this->recordingOutput());
		$this->step($this->store(), configured: false)->run($this->recordingOutput());

		$this->assertSame(
			[
				['warning', 'OpenRegister is not available. Skipping case-flow seed.'],
				['warning', 'ObjectService unavailable. Skipping case-flow seed.'],
				['warning', 'Register or schemas not configured. Skipping case-flow seed.'],
			],
			$this->said
		);
		$this->assertSame([], $this->saves);
	}//end testEachSkipSaysWhy()

	/**
	 * A complete run says what it created and records the seeded cases.
	 *
	 * @return void
	 */
	public function testACompleteRunSummarisesAndRecordsTheSeed(): void {
		$this->step($this->store())->run($this->recordingOutput());

		$this->assertCount(1, $this->said);
		$this->assertSame(
			['info', sprintf(
				'Case-flow seed: %d case type(s), %d status(es), %d case(s) created; %d already present.',
				$this->saves['case_type_schema'],
				$this->saves['status_type_schema'],
				$this->saves['case_schema'],
				0
			)],
			$this->said[0]
		);
		$this->assertNotSame('', $this->appConfig[CaseFlowSeedDataRepairStep::CASES_SEEDED_KEY] ?? '');
	}//end testACompleteRunSummarisesAndRecordsTheSeed()

	/**
	 * A case type that fails is reported and logged, the summary still comes, and the seed is not recorded.
	 *
	 * @return void
	 */
	public function testAFailedCaseTypeIsReportedAndTheSeedNotRecorded(): void {
		$this->step($this->store(failsOnSchema: 'case_type_schema'))->run($this->recordingOutput());

		$failures = array_values(array_filter($this->said, static fn (array $line): bool => $line[0] === 'warning'));
		$this->assertNotSame([], $failures);
		$this->assertMatchesRegularExpression('/^Case-flow seed failed for ".+": schema rejected the object$/', $failures[0][1]);
		$logs = array_values(array_filter($this->said, static fn (array $line): bool => $line[0] === 'log'));
		$this->assertCount(count($failures), $logs);
		$this->assertMatchesRegularExpression('/^Dossiq case-flow seed failed \{"caseType":".+","exception":"schema rejected the object"\}$/', $logs[0][1]);
		$this->assertSame(['info', 'Case-flow seed: 0 case type(s), 0 status(es), 0 case(s) created; 0 already present.'], end($this->said));
		$this->assertArrayNotHasKey(CaseFlowSeedDataRepairStep::CASES_SEEDED_KEY, $this->appConfig);
	}//end testAFailedCaseTypeIsReportedAndTheSeedNotRecorded()

	/**
	 * Without the demo data decision no cases are seeded and the seed is not recorded.
	 *
	 * @return void
	 */
	public function testWithoutDemoDataTheCasesAreNotRecorded(): void {
		$this->appConfig = ['demo_data_decided' => 'skipped'];
		$this->step($this->store())->run($this->recordingOutput());

		$this->assertArrayNotHasKey('case_schema', $this->saves);
		$this->assertArrayNotHasKey(CaseFlowSeedDataRepairStep::CASES_SEEDED_KEY, $this->appConfig);
	}//end testWithoutDemoDataTheCasesAreNotRecorded()
}//end class
