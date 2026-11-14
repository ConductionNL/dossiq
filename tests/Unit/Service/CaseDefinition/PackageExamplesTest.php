<?php

/**
 * Unit tests for PackageExamples.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageExamples;
use OCA\Dossiq\Service\CaseDefinition\PackageInstallRun;
use OCA\Dossiq\Service\CaseDefinition\PackageSchemaReferences;
use OCA\Dossiq\Service\DemoDataService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A package's example rows are written only on an instance that opted into
 * example data, with the JSON-text properties of the target schema encoded,
 * and only when the package declares the examples part.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageExamples
 * @uses   \OCA\Dossiq\Service\CaseDefinition\PackageExampleDates
 * @uses   \OCA\Dossiq\Service\CaseDefinition\PackageInstallRun
 * @uses   \OCA\Dossiq\Service\Support\JsonEncodedStringProperties
 */
class PackageExamplesTest extends TestCase {
	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var PackageExamples
	 */
	private PackageExamples $examples;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->examples = new PackageExamples(appConfig: $this->appConfig, logger: $this->logger);
	}//end setUp()

	/**
	 * Answer the example-data decision with the given value.
	 *
	 * @param string $decided The stored decision.
	 *
	 * @return void
	 */
	private function decided(string $decided): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, DemoDataService::DECIDED_KEY, '')
			->willReturn($decided);
	}//end decided()

	/**
	 * No examples are nothing to write, without reading the decision.
	 *
	 * @return void
	 */
	public function testNoExamplesWriteNothing(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$this->assertSame([], $this->examples->toWrite(package: 'pkg', examples: []));
	}//end testNoExamplesWriteNothing()

	/**
	 * Without the opt-in the examples are skipped and the skip is logged.
	 *
	 * @return void
	 */
	public function testExamplesAreSkippedWithoutTheOptIn(): void {
		$this->decided(decided: 'declined');
		$this->logger->expects($this->once())->method('info')->with(
			'Package {id}: example rows skipped, this instance has not opted into example data',
			['id' => 'pkg-woo']
		);

		$result = $this->examples->toWrite(package: 'pkg-woo', examples: [['schema' => 'case_schema', 'rows' => [['title' => 'A']]]]);

		$this->assertSame([], $result);
	}//end testExamplesAreSkippedWithoutTheOptIn()

	/**
	 * With the opt-in, the target schema's JSON-text properties are encoded
	 * and every other value is kept.
	 *
	 * @return void
	 */
	public function testOptedInExamplesAreEncodedForTheirSchema(): void {
		$this->decided(decided: DemoDataService::DECIDED_INSTALLED);
		$this->logger->expects($this->never())->method('info');

		$result = $this->examples->toWrite(
			package: 'pkg',
			examples: [
				[
					'schema' => 'case_schema',
					'match' => ['identifier'],
					'rows' => [
						['identifier' => 'Z-1', 'statusHistory' => [['status' => 'open']], 'title' => 'Melding'],
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'schema' => 'case_schema',
					'match' => ['identifier'],
					'rows' => [
						['identifier' => 'Z-1', 'statusHistory' => '[{"status":"open"}]', 'title' => 'Melding'],
					],
				],
			],
			$result
		);
	}//end testOptedInExamplesAreEncodedForTheirSchema()

	/**
	 * Rows without a schema name, or for a schema without JSON-text
	 * properties, keep their arrays.
	 *
	 * @return void
	 */
	public function testRowsWithoutAKnownSchemaKeepTheirArrays(): void {
		$this->decided(decided: DemoDataService::DECIDED_INSTALLED);

		$result = $this->examples->toWrite(
			package: 'pkg',
			examples: [
				['rows' => [['statusHistory' => ['a']]]],
				['schema' => 'unknown_schema', 'rows' => [['statusHistory' => ['b']]]],
				['schema' => 'case_schema'],
			]
		);

		$this->assertSame(
			[
				['rows' => [['statusHistory' => ['a']]]],
				['schema' => 'unknown_schema', 'rows' => [['statusHistory' => ['b']]]],
				['schema' => 'case_schema', 'rows' => []],
			],
			$result
		);
	}//end testRowsWithoutAKnownSchemaKeepTheirArrays()

	/**
	 * A run without the examples part reads no example files.
	 *
	 * @return void
	 */
	public function testARunWithoutTheExamplesPartHasNoGroups(): void {
		$packages = $this->createMock(PackageDirectory::class);
		$packages->expects($this->never())->method('rowsUnder');
		$references = $this->createMock(PackageSchemaReferences::class);
		$references->expects($this->never())->method('seedGroup');
		$run = new PackageInstallRun(id: 'pkg', manifest: ['parts' => ['caseTypes']], package: ['files' => [], 'digest' => 'd'], overLocalChanges: false);

		$this->assertSame([], $this->examples->groups(run: $run, packages: $packages, references: $references));
	}//end testARunWithoutTheExamplesPartHasNoGroups()

	/**
	 * Each example under `examples/` becomes one seed group.
	 *
	 * @return void
	 */
	public function testEachExampleBecomesASeedGroup(): void {
		$this->decided(decided: DemoDataService::DECIDED_INSTALLED);
		$files = ['examples/cases.json' => '{}', 'caseTypes/a.json' => '{}'];
		$packages = $this->createMock(PackageDirectory::class);
		$packages->expects($this->once())->method('rowsUnder')
			->with($files, 'examples/')
			->willReturn([['schema' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]], ['rows' => [['x' => 1]]]]);
		$references = $this->createMock(PackageSchemaReferences::class);
		$references->expects($this->exactly(2))->method('seedGroup')->willReturnCallback(
			static fn (array $seed): array => ['schemaKey' => (string)($seed['schema'] ?? ''), 'rows' => $seed['rows']]
		);
		$run = new PackageInstallRun(id: 'pkg', manifest: ['parts' => ['caseTypes', 'examples']], package: ['files' => $files, 'digest' => 'd'], overLocalChanges: false);

		$groups = $this->examples->groups(run: $run, packages: $packages, references: $references);

		$this->assertSame(
			[
				['schemaKey' => 'case_schema', 'rows' => [['identifier' => 'Z-1']]],
				['schemaKey' => '', 'rows' => [['x' => 1]]],
			],
			$groups
		);
	}//end testEachExampleBecomesASeedGroup()
}//end class
