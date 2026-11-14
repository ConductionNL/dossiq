<?php

/**
 * Unit tests for ReviewSummary.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Review\ReviewConfiguration;
use OCA\Dossiq\Review\ReviewSummary;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use PHPUnit\Framework\TestCase;

/**
 * A case's review summary: verdicts counted over the case's own documents only.
 *
 * @covers \OCA\Dossiq\Review\ReviewSummary
 * @uses   \OCA\Dossiq\Review\CaseAssessmentRows
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class ReviewSummaryTest extends TestCase {

	/**
	 * The assessment rows the register holds.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * Seed an empty register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
	}//end setUp()

	/**
	 * Store one assessment of the case.
	 *
	 * @param string $uuid           The row id.
	 * @param array  $row            The row, without its caseRef.
	 * @param string $caseId         The case it belongs to.
	 *
	 * @return void
	 */
	private function assessment(string $uuid, array $row, string $caseId = 'case-1'): void {
		$this->register->seed(schema: 'wooDocumentAssessment', uuid: $uuid, row: ['caseRef' => $caseId] + $row);
	}//end assessment()

	/**
	 * A summary over the case documents d1..d3, with the given configuration.
	 *
	 * @param array $configuration The case's review configuration.
	 *
	 * @return ReviewSummary
	 */
	private function summary(array $configuration = ['enabled' => true]): ReviewSummary {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);

		$documents = $this->createMock(originalClassName: WooCaseDocuments::class);
		$documents->method('idsFor')->with('case-1')->willReturn(['d1', 'd2', 'd3']);

		$pages = $this->createMock(originalClassName: PagesSeen::class);
		$pages->method('unseenInCase')->with('case-1')->willReturn(['d3' => [2, 4]]);
		$reviews = $this->createMock(originalClassName: DocumentRelevance::class);
		$reviews->method('forCase')->with('case-1')->willReturn(['d1' => ['inScope' => true]]);
		$reviews->expects($this->any())->method('count')
			->with(['d1', 'd2', 'd3'], ['d1' => ['inScope' => true]])
			->willReturn(['unmarked' => 2, 'inScope' => 1, 'outOfScope' => 0]);
		$reviews->method('pages')->willReturn($pages);

		$assessments = $this->createMock(originalClassName: WOODocumentAssessmentService::class);
		$assessments->method('getOutstanding')->with('case-1')->willReturn(['count' => 1, 'documents' => ['d3']]);

		$config = $this->createMock(originalClassName: ReviewConfiguration::class);
		$config->method('forCase')->with('case-1')->willReturn($configuration);

		return new ReviewSummary(
			settingsService: $settings,
			reviews: $reviews,
			assessments: $assessments,
			caseDocuments: $documents,
			configuration: $config,
		);
	}//end summary()

	/**
	 * Only known verdicts on the case's own documents are counted; the rest of the summary comes
	 * from the collaborators unchanged.
	 *
	 * @return void
	 */
	public function testCountsKnownVerdictsOnTheCasesDocumentsOnly(): void {
		$this->assessment(uuid: 'a1', row: ['documentRef' => 'd1', 'classification' => 'openbaar']);
		$this->assessment(uuid: 'a2', row: ['documentRef' => 'd2', 'classification' => 'deels_openbaar']);
		$this->assessment(uuid: 'a3', row: ['documentRef' => 'd3', 'classification' => 'deels_openbaar']);
		$this->assessment(uuid: 'a4', row: ['documentRef' => 'gone', 'classification' => 'niet_openbaar']);
		$this->assessment(uuid: 'a6', row: ['documentRef' => 'd2', 'classification' => 'onbekend']);
		$this->assessment(uuid: 'a7', row: ['documentRef' => 'd2']);
		$this->assessment(uuid: 'a8', row: ['documentRef' => 'd1', 'classification' => 'niet_openbaar'], caseId: 'case-2');

		$this->assertSame(
			[
				'enabled' => true,
				'documents' => 3,
				'relevance' => ['unmarked' => 2, 'inScope' => 1, 'outOfScope' => 0],
				'verdicts' => ['openbaar' => 1, 'deels_openbaar' => 2, 'niet_openbaar' => 0, 'total' => 3],
				'outstanding' => ['count' => 1, 'documents' => ['d3']],
				'unseen' => ['d3' => [2, 4]],
			],
			$this->summary()->forCase(caseId: 'case-1')
		);
	}//end testCountsKnownVerdictsOnTheCasesDocumentsOnly()

	/**
	 * Review is on only for a strict true: a missing flag or a truthy string is off.
	 *
	 * @return void
	 */
	public function testReviewIsEnabledOnlyByAStrictTrue(): void {
		$this->assertFalse($this->summary(configuration: [])->forCase(caseId: 'case-1')['enabled']);
		$this->assertFalse($this->summary(configuration: ['enabled' => 'true'])->forCase(caseId: 'case-1')['enabled']);
		$this->assertFalse($this->summary(configuration: ['enabled' => 1])->forCase(caseId: 'case-1')['enabled']);
	}//end testReviewIsEnabledOnlyByAStrictTrue()

	/**
	 * A document counts as assessed once any of its rows carries a classification, whether or
	 * not it is still one of the case's documents.
	 *
	 * @return void
	 */
	public function testAssessedListsEveryClassifiedDocumentOnce(): void {
		$this->assessment(uuid: 'a1', row: ['documentRef' => 'd1', 'classification' => 'openbaar']);
		$this->assessment(uuid: 'a2', row: ['documentRef' => 'd1', 'classification' => 'niet_openbaar']);
		$this->assessment(uuid: 'a3', row: ['documentRef' => 'd2', 'classification' => '']);
		$this->assessment(uuid: 'a4', row: ['documentRef' => '', 'classification' => 'openbaar']);
		$this->assessment(uuid: 'a5', row: ['documentRef' => 'gone', 'classification' => 'onbekend']);
		$this->assessment(uuid: 'a6', row: ['documentRef' => 'd3', 'classification' => 'openbaar'], caseId: 'case-2');

		$this->assertSame(['d1' => true, 'gone' => true], $this->summary()->assessed(caseId: 'case-1'));
	}//end testAssessedListsEveryClassifiedDocumentOnce()
}//end class
