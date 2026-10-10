<?php

/**
 * Unit tests for the CreateWooDocumentReviews repair step.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CreateWooDocumentReviews;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every assessed document gets one in-scope review, once.
 *
 * @covers \OCA\Dossiq\Repair\CreateWooDocumentReviews
 * @covers \OCA\Dossiq\Woo\WooDocumentReviews
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
 */
class CreateWooDocumentReviewsTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed two cases: three assessed documents, one of them already reviewed out of scope.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar', 'assessedBy' => 'anna', 'assessedAt' => '2026-10-01T10:00:00+02:00']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: ['caseRef' => 'case-x', 'documentRef' => 'doc-2', 'classification' => 'niet_openbaar', 'assessedBy' => 'bram']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-3', row: ['caseRef' => 'case-y', 'documentRef' => 'doc-3', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-4', row: ['caseRef' => 'case-y', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'wooDocumentReview', uuid: 'r-3', row: ['case' => 'case-y', 'documentRef' => 'doc-3', 'relevance' => 'out-of-scope']);
	}//end setUp()

	/**
	 * The repair over the store.
	 *
	 * @param array<string, string> $config The configured keys.
	 *
	 * @return CreateWooDocumentReviews The step.
	 */
	private function step(array $config = ['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment', 'woo_review_schema' => 'wooDocumentReview']): CreateWooDocumentReviews {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));

		return new CreateWooDocumentReviews(
			settingsService: $settings,
			reviews: new WooDocumentReviews(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)),
		);
	}//end step()

	/**
	 * Each assessed document without a review gets one in-scope review, marked by its assessor.
	 *
	 * @return void
	 */
	public function testEveryAssessedDocumentGetsOneInScopeReview(): void {
		$this->step()->run($this->createMock(IOutput::class));

		$byDocument = array_column($this->store->all('wooDocumentReview'), null, 'documentRef');
		$this->assertCount(3, $byDocument);
		$this->assertSame('in-scope', $byDocument['doc-1']['relevance']);
		$this->assertSame('reviewer', $byDocument['doc-1']['relevanceSource']);
		$this->assertSame('anna', $byDocument['doc-1']['markedBy']);
		$this->assertSame('2026-10-01T10:00:00+02:00', $byDocument['doc-1']['markedAt']);
		$this->assertSame('case-x', $byDocument['doc-2']['case']);
		$this->assertArrayNotHasKey('markedAt', $byDocument['doc-2']);
		// A review that exists keeps what it says.
		$this->assertSame('out-of-scope', $byDocument['doc-3']['relevance']);
	}//end testEveryAssessedDocumentGetsOneInScopeReview()

	/**
	 * A second run creates nothing.
	 *
	 * @return void
	 */
	public function testASecondRunCreatesNothing(): void {
		$this->step()->run($this->createMock(IOutput::class));
		$writes = $this->store->writes;

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame($writes, $this->store->writes);
		$this->assertCount(3, $this->store->all('wooDocumentReview'));
	}//end testASecondRunCreatesNothing()

	/**
	 * Without the review schema configured, the step skips and writes nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheReviewSchemaItSkips(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('skipped'));

		$this->step(config: ['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment'])->run($output);

		$this->assertSame(0, $this->store->writes);
		$this->assertSame('Mark every assessed Woo document as in scope', $this->step()->getName());
	}//end testWithoutTheReviewSchemaItSkips()
}//end class
