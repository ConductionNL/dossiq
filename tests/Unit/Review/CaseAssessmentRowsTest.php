<?php

/**
 * The assessments of one case the caller may read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Review
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\CaseAssessmentRows;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Review\CaseAssessmentRows
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseAssessmentRowsTest extends TestCase {

	/**
	 * The reader over a register, or over no OpenRegister at all.
	 *
	 * @param InMemoryRegister|null $register The register, or null.
	 * @param string                $schema   The configured assessment schema.
	 *
	 * @return CaseAssessmentRows
	 */
	private function rows(?InMemoryRegister $register, string $schema = 'wooDocumentAssessment'): CaseAssessmentRows {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => $schema,
			][$key] ?? $default
		);

		return new CaseAssessmentRows(settingsService: $settings);
	}//end rows()

	/**
	 * Only the case's own assessments are read.
	 *
	 * @return void
	 */
	public function testReadsOnlyTheCasesAssessments(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a1', row: ['caseRef' => 'case-1', 'documentRef' => 'd1']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a2', row: ['caseRef' => 'case-2', 'documentRef' => 'd2']);

		$rows = $this->rows(register: $register)->forCase(caseId: 'case-1');

		$this->assertSame(['d1'], array_column($rows, 'documentRef'));
	}//end testReadsOnlyTheCasesAssessments()

	/**
	 * Without OpenRegister or an assessment schema nothing is read.
	 *
	 * @return void
	 */
	public function testIsEmptyWithoutOpenRegisterOrASchema(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a1', row: ['caseRef' => 'case-1', 'documentRef' => 'd1']);

		$this->assertSame([], $this->rows(register: null)->forCase(caseId: 'case-1'));
		$this->assertSame([], $this->rows(register: $register, schema: '')->forCase(caseId: 'case-1'));
	}//end testIsEmptyWithoutOpenRegisterOrASchema()
}//end class
