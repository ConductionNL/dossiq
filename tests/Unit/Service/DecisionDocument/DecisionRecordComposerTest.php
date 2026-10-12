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
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 *
	 * @return DecisionRecordComposer
	 */
	private function composer(?LoggerInterface $logger = null): DecisionRecordComposer {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['case_schema' => 'case', 'woo_assessment_schema' => 'assessment'][$key] ?? $default
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
}//end class
