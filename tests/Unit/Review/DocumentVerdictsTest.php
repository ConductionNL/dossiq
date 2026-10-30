<?php

/**
 * The verdicts on a case's documents and the grounds they cite.
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

use OCA\Dossiq\Review\DocumentVerdicts;
use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Review\DocumentVerdicts
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DocumentVerdictsTest extends TestCase {

	/**
	 * The verdicts over a register, with seven documents under review.
	 *
	 * @param InMemoryRegister $register The register.
	 * @param bool             $groundsReadable Whether the list of grounds answers.
	 *
	 * @return DocumentVerdicts
	 */
	private function verdicts(InMemoryRegister $register, bool $groundsReadable = true): DocumentVerdicts {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);

		$assessments = $this->createMock(originalClassName: WOODocumentAssessmentService::class);
		$assessments->method('getDocumentsUnderReview')->willReturn(['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7']);

		$titles = $this->createMock(originalClassName: OutstandingDocuments::class);
		$titles->method('titleOf')->willReturnCallback(
			static fn (string $documentId): string => [
				'd2' => 'Mail aannemer over planning',
				'd4' => 'Klachtenlijst bewoners Lindelaan',
			][$documentId] ?? $documentId
		);

		$grounds = $this->createMock(originalClassName: WooRefusalGrounds::class);
		if ($groundsReadable === true) {
			$grounds->method('byCode')->willReturnCallback(
				static fn (string $code): ?array => [
					'5.1.2.e' => ['label' => 'Eerbiediging van de persoonlijke levenssfeer'],
				][$code] ?? null
			);
		} else {
			$grounds->method('byCode')->willThrowException(new RuntimeException('register down'));
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf(str_replace('(partly)', '(deels)', $text), $params)
		);

		return new DocumentVerdicts(settingsService: $settings, assessments: $assessments, titles: $titles, grounds: $grounds, l10n: $l10n);
	}//end verdicts()

	/**
	 * Four of seven documents assessed on the Lindelaan case.
	 *
	 * @return InMemoryRegister
	 */
	private function lindelaan(): InMemoryRegister {
		$register = new InMemoryRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a1', row: ['caseRef' => 'case-1', 'documentRef' => 'd1', 'classification' => 'openbaar']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a2', row: [
				'caseRef' => 'case-1',
				'documentRef' => 'd2',
				'classification' => 'deels_openbaar',
				'weigeringsgronden' => ['5.1.2.e'],
			]);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a3', row: ['caseRef' => 'case-1', 'documentRef' => 'd3', 'classification' => 'openbaar']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a4', row: [
				'caseRef' => 'case-1',
				'documentRef' => 'd4',
				'classification' => 'niet_openbaar',
				'weigeringsgronden' => ['5.1.2.e'],
			]);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a5', row: ['caseRef' => 'case-1', 'documentRef' => 'd5']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a6', row: ['caseRef' => 'case-1', 'documentRef' => 'gone', 'classification' => 'openbaar']);
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'a7', row: ['caseRef' => 'case-2', 'documentRef' => 'd6', 'classification' => 'openbaar']);

		return $register;
	}//end lindelaan()

	/**
	 * REQ-CDV-001: four of seven assessed, counted per verdict, the ground with both documents.
	 *
	 * @return void
	 */
	public function testCountsTheVerdictsAndListsTheGroundsWithTheirDocuments(): void {
		$answer = $this->verdicts(register: $this->lindelaan())->forCase(caseId: 'case-1');

		self::assertSame(expected: 7, actual: $answer['documents']);
		self::assertSame(expected: 4, actual: $answer['assessed']);
		self::assertSame(expected: 3, actual: $answer['remaining']);
		self::assertSame(expected: ['openbaar' => 2, 'deels_openbaar' => 1, 'niet_openbaar' => 1], actual: $answer['counts']);
		self::assertSame(
			expected: [[
				'code' => '5.1.2.e',
				'label' => 'Eerbiediging van de persoonlijke levenssfeer',
				'documentList' => 'Mail aannemer over planning (deels), Klachtenlijst bewoners Lindelaan',
			]],
			actual: $answer['grounds']
		);
	}//end testCountsTheVerdictsAndListsTheGroundsWithTheirDocuments()

	/**
	 * Each document under review with its verdict, `open` while it has none (the Files tab's row pill).
	 *
	 * @return void
	 */
	public function testNamesEachDocumentsVerdictAndOpenForTheRest(): void {
		$answer = $this->verdicts(register: $this->lindelaan())->byDocument(caseId: 'case-1');

		self::assertSame(
			expected: [
				'd1' => 'openbaar',
				'd2' => 'deels_openbaar',
				'd3' => 'openbaar',
				'd4' => 'niet_openbaar',
				'd5' => 'open',
				'd6' => 'open',
				'd7' => 'open',
			],
			actual: $answer
		);
	}//end testNamesEachDocumentsVerdictAndOpenForTheRest()

	/**
	 * A list of grounds that cannot be read leaves the code without a label, not the answer without grounds.
	 *
	 * @return void
	 */
	public function testAnUnreadableGroundsListLeavesTheLabelEmpty(): void {
		$answer = $this->verdicts(register: $this->lindelaan(), groundsReadable: false)->forCase(caseId: 'case-1');

		self::assertSame(expected: '5.1.2.e', actual: $answer['grounds'][0]['code']);
		self::assertSame(expected: '', actual: $answer['grounds'][0]['label']);
	}//end testAnUnreadableGroundsListLeavesTheLabelEmpty()

	/**
	 * Nothing assessed: every document remains and no ground is cited.
	 *
	 * @return void
	 */
	public function testNothingAssessedLeavesEveryDocumentRemaining(): void {
		$answer = $this->verdicts(register: new InMemoryRegister())->forCase(caseId: 'case-1');

		self::assertSame(expected: ['documents' => 7, 'assessed' => 0, 'remaining' => 7, 'counts' => [], 'grounds' => []], actual: $answer);
	}//end testNothingAssessedLeavesEveryDocumentRemaining()
}//end class
