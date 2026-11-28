<?php

/**
 * Unit tests for AssessmentBatchWriter: a batch of assessments validated, stamped and stored on one case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\DocumentRelevanceInterface;
use OCA\Dossiq\Kernel\Contracts\PagesSeenInterface;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Assessment\AssessmentBatchWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A batch of assessments on one case, through AssessmentBatchWriter.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AssessmentBatchWriterTest extends TestCase {

	private const SCHEMA = 'wooDocumentAssessment';

	private const CONFIG = ['register' => 'dossiq', 'woo_assessment_schema' => self::SCHEMA];

	private LifecycleRegister $register;

	protected function setUp(): void {
		$this->register = new LifecycleRegister();
	}//end setUp()

	/**
	 * A writer over the register.
	 *
	 * @param array<string, string>           $config  The configuration values.
	 * @param CaseReviewerAccess|null         $access  The reviewer rule, or none.
	 * @param DocumentRelevanceInterface|null $reviews The document reviews, or none.
	 * @param LoggerInterface|null            $logger  The logger.
	 * @param bool                            $withOr  Whether OpenRegister is available.
	 *
	 * @return AssessmentBatchWriter The writer.
	 */
	private function writer(
		array $config = self::CONFIG,
		?CaseReviewerAccess $access = null,
		?DocumentRelevanceInterface $reviews = null,
		?LoggerInterface $logger = null,
		bool $withOr = true,
	): AssessmentBatchWriter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withOr === true ? $this->register : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$groundSettings = $this->createMock(SettingsService::class);
		$groundSettings->method('getObjectService')->willReturn(RefusalGroundStore::seeded());
		$logger = ($logger ?? new NullLogger());

		return new AssessmentBatchWriter(
			settingsService: $settings,
			logger: $logger,
			refusalGrounds: new WooRefusalGrounds(settingsService: $groundSettings, logger: $logger),
			reviewerAccess: $access,
			reviews: $reviews
		);
	}//end writer()

	/**
	 * A new assessment is stamped with the case, the list version and its reviewer, without caller identity keys.
	 *
	 * @return void
	 */
	public function testANewAssessmentIsStampedAndStored(): void {
		$result = $this->writer()->write(
			caseId: 'case-1',
			assessments: [['id' => 'forged', 'documentRef' => 'doc-1', 'classification' => 'openbaar']],
			userId: 'reviewer-1'
		);

		$this->assertSame([], $result['errors']);
		$this->assertCount(1, $result['saved']);
		$rows = array_values($this->register->all(self::SCHEMA));
		$this->assertCount(1, $rows);
		$this->assertNotSame('forged', $rows[0]['id']);
		$this->assertSame('case-1', $rows[0]['caseRef']);
		$this->assertSame('reviewer-1', $rows[0]['assessedBy']);
		$this->assertSame(WooRefusalGrounds::LIST_VERSION, $rows[0]['groundsListVersion']);
		$this->assertArrayHasKey('assessedAt', $rows[0]);
	}//end testANewAssessmentIsStampedAndStored()

	/**
	 * A second verdict on the same document updates the stored one, and only creates count.
	 *
	 * @return void
	 */
	public function testASecondVerdictUpdatesTheStoredOne(): void {
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('refusalFor')->willReturn(null);
		$access->expects($this->once())->method('reconcileAfterCreate')->with('case-1', 1);

		$result = $this->writer(access: $access)->write(
			caseId: 'case-1',
			assessments: [
				['documentRef' => 'doc-1', 'classification' => 'openbaar'],
				['documentRef' => 'doc-2', 'classification' => 'openbaar'],
			],
			userId: 'reviewer-1'
		);

		$this->assertCount(2, $result['saved']);
		$this->assertCount(2, $this->register->all(self::SCHEMA));
		$this->assertSame('reviewer-1', $this->register->row(self::SCHEMA, 'a-1')['assessedBy']);
	}//end testASecondVerdictUpdatesTheStoredOne()

	/**
	 * An invalid payload and a refused reviewer land in errors and store nothing.
	 *
	 * @return void
	 */
	public function testInvalidAndRefusedVerdictsAreErrors(): void {
		$this->register->seed(schema: self::SCHEMA, uuid: 'a-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'assessedBy' => 'someone-else']);
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('refusalFor')->willReturn(['documentRef' => 'doc-1', 'errors' => ['owner' => 'Not yours.']]);

		$result = $this->writer(access: $access)->write(
			caseId: 'case-1',
			assessments: [
				['classification' => 'openbaar'],
				['documentRef' => 'doc-1', 'classification' => 'openbaar'],
			],
			userId: 'reviewer-1'
		);

		$this->assertSame([], $result['saved']);
		$this->assertSame('unknown', $result['errors'][0]['documentRef']);
		$this->assertArrayHasKey('documentRef', $result['errors'][0]['errors']);
		$this->assertSame(['documentRef' => 'doc-1', 'errors' => ['owner' => 'Not yours.']], $result['errors'][1]);
		$this->assertSame('someone-else', $this->register->row(self::SCHEMA, 'a-1')['assessedBy']);
	}//end testInvalidAndRefusedVerdictsAreErrors()

	/**
	 * The judged documents are marked in scope; a refused mark is logged and keeps the verdict.
	 *
	 * @return void
	 */
	public function testJudgedDocumentsAreMarkedInScope(): void {
		$pages = $this->createMock(PagesSeenInterface::class);
		$pages->method('refusalFor')->willReturn([]);
		$reviews = $this->createMock(DocumentRelevanceInterface::class);
		$reviews->method('pages')->willReturn($pages);
		$reviews->expects($this->once())->method('markInScopeWhereUnmarked')
			->with('case-1', ['doc-1'], 'reviewer-1')
			->willThrowException(new RefusedException(rule: 'review-unwritable', sentence: 'Cannot write.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$result = $this->writer(reviews: $reviews, logger: $logger)->write(
			caseId: 'case-1',
			assessments: [['documentRef' => 'doc-1', 'classification' => 'openbaar']],
			userId: 'reviewer-1'
		);

		$this->assertCount(1, $result['saved']);
	}//end testJudgedDocumentsAreMarkedInScope()

	/**
	 * A page the reviewer has not seen refuses the verdict.
	 *
	 * @return void
	 */
	public function testAnUnseenPageRefusesTheVerdict(): void {
		$pages = $this->createMock(PagesSeenInterface::class);
		$pages->method('refusalFor')->willReturn(['pagesSeen' => 'Page 3 not seen.']);
		$reviews = $this->createMock(DocumentRelevanceInterface::class);
		$reviews->method('pages')->willReturn($pages);

		$result = $this->writer(reviews: $reviews)->write(
			caseId: 'case-1',
			assessments: [['documentRef' => 'doc-1', 'classification' => 'openbaar']],
			userId: 'reviewer-1'
		);

		$this->assertSame(['pagesSeen' => 'Page 3 not seen.'], $result['errors'][0]['errors']);
		$this->assertSame([], $this->register->all(self::SCHEMA));
	}//end testAnUnseenPageRefusesTheVerdict()

	/**
	 * A batch needs OpenRegister and a configured schema.
	 *
	 * @return void
	 */
	public function testABatchNeedsOpenRegisterAndTheSchema(): void {
		try {
			$this->writer(withOr: false)->write(caseId: 'case-1', assessments: [], userId: 'reviewer-1');
			$this->fail('Without OpenRegister the batch should be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister is not available', $e->getMessage());
		}

		$this->expectExceptionMessage('WOO assessment schema not configured');
		$this->writer(config: ['register' => 'dossiq'])->write(caseId: 'case-1', assessments: [], userId: 'reviewer-1');
	}//end testABatchNeedsOpenRegisterAndTheSchema()
}//end class
