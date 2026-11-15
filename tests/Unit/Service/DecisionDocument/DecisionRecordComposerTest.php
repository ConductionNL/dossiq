<?php

/**
 * A decision record names its case by title and tallies the case's assessments.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\DecisionDocument\DecisionRecordComposer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionRecordComposer
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DecisionRecordComposerTest extends TestCase {

	/**
	 * A composer on the case and assessment schemas.
	 *
	 * @param LoggerInterface|null       $logger The logger, or a silent one.
	 * @param array<string, string>|null $config The app config, or both schemas configured.
	 *
	 * @return DecisionRecordComposer
	 */
	private function composer(?LoggerInterface $logger = null, ?array $config = null): DecisionRecordComposer {
		$config = ($config ?? ['case_schema' => 'case', 'woo_assessment_schema' => 'assessment']);
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $config[$key] ?? $default
		);

		return new DecisionRecordComposer(settingsService: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end composer()

	/**
	 * The record carries the case title, the count, the tally and each ground once.
	 *
	 * @return void
	 */
	public function testTheRecordTalliesTheAssessmentsOfTheCase(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Parkeren']);
		$register->seed(schema: 'assessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'classification' => 'openbaar']);
		$register->seed(schema: 'assessment', uuid: 'a-2', row: ['caseRef' => 'case-1', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']]);
		$register->seed(schema: 'assessment', uuid: 'a-3', row: ['caseRef' => 'case-1', 'classification' => 'deels_openbaar', 'weigeringsgronden' => ['5.1.2.e', '5.2']]);
		$register->seed(schema: 'assessment', uuid: 'a-4', row: ['caseRef' => 'case-2', 'classification' => 'openbaar']);

		$record = $this->composer()->compose(objectService: $register, register: 'dossiq', caseId: 'case-1');

		self::assertSame('Parkeren', $record['caseTitle']);
		self::assertSame(3, $record['assessmentCount']);
		self::assertSame(['openbaar' => 1, 'deels_openbaar' => 1, 'niet_openbaar' => 1], $record['summary']);
		self::assertSame(['5.1.2.e', '5.2'], $record['weigeringsgronden']);
	}//end testTheRecordTalliesTheAssessmentsOfTheCase()

	/**
	 * A case that cannot be read costs the record its title, and is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseCostsOnlyTheTitle(): void {
		$register = new class extends InMemoryRegister {
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				throw new RuntimeException('down');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$record = $this->composer(logger: $logger)->compose(objectService: $register, register: 'dossiq', caseId: 'case-1');

		self::assertSame('', $record['caseTitle']);
		self::assertSame(0, $record['assessmentCount']);
		self::assertSame([], $record['weigeringsgronden']);
	}//end testAnUnreadableCaseCostsOnlyTheTitle()

	/**
	 * Without a case schema the case is not read and the record has no title.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaTheCaseIsNotRead(): void {
		$register = new class extends InMemoryRegister {
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				throw new RuntimeException('the case must not be read');
			}
		};
		$register->seed(schema: 'assessment', uuid: 'a-1', row: ['caseRef' => 'case-1', 'classification' => 'openbaar']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$record = $this->composer(logger: $logger, config: ['woo_assessment_schema' => 'assessment'])
			->compose(objectService: $register, register: 'dossiq', caseId: 'case-1');

		self::assertSame('', $record['caseTitle']);
		self::assertSame(1, $record['assessmentCount']);
		self::assertSame(['openbaar' => 1, 'deels_openbaar' => 0, 'niet_openbaar' => 0], $record['summary']);
	}//end testWithoutACaseSchemaTheCaseIsNotRead()

	/**
	 * A case title that is not a string is answered as no title.
	 *
	 * @return void
	 */
	public function testANonStringCaseTitleIsAnsweredAsNoTitle(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => ['nl' => 'Parkeren']]);

		$record = $this->composer()->compose(objectService: $register, register: 'dossiq', caseId: 'case-1');

		self::assertSame('', $record['caseTitle']);
	}//end testANonStringCaseTitleIsAnsweredAsNoTitle()

	/**
	 * Without an assessment schema no assessment is searched and the tally is zero.
	 *
	 * @return void
	 */
	public function testWithoutAnAssessmentSchemaNothingIsTallied(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Parkeren']);
		$register->seed(schema: '', uuid: 'a-1', row: ['caseRef' => 'case-1', 'classification' => 'openbaar', 'weigeringsgronden' => ['5.2']]);

		$record = $this->composer(config: ['case_schema' => 'case'])->compose(objectService: $register, register: 'dossiq', caseId: 'case-1');

		self::assertSame('Parkeren', $record['caseTitle']);
		self::assertSame(0, $record['assessmentCount']);
		self::assertSame(['openbaar' => 0, 'deels_openbaar' => 0, 'niet_openbaar' => 0], $record['summary']);
		self::assertSame([], $record['weigeringsgronden']);
	}//end testWithoutAnAssessmentSchemaNothingIsTallied()
}//end class
