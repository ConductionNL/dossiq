<?php

/**
 * WOORedactionService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\WOORedactionService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOORedactionService.
 *
 * ⚠️ THE `uses` ANNOTATION BELOW IS LOAD-BEARING, not decoration, and its name
 * must never be written with an at-sign anywhere in this prose. PHPUnit parses
 * annotations ANYWHERE in a docblock, so even a backtick-quoted mention
 * becomes a second, malformed annotation and EVERY test in the class errors as
 * invalid. That is how this comment shipped twice: once naming the tag, and
 * once again inside the warning about naming the tag.
 *
 * Why the annotation is needed: the service resolves the document app through
 * `FleetAppId::isEnabledForUser()` since #1863, so these tests execute that
 * class. PHPUnit reports code executed but not declared as RISKY, the suite
 * runs with `failOnRisky`, and two risky tests turn 3052 passing ones into a
 * red run that prints `OK, but there were issues!` and exits 1. Reading the
 * summary line rather than the exit code hides it completely.
 *
 * @covers \OCA\Dossiq\Service\WOORedactionService
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class WOORedactionServiceTest extends TestCase {

	/**
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var FilinqRedactionClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private FilinqRedactionClient $filinq;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOORedactionService
	 */
	private WOORedactionService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->filinq = $this->createMock(FilinqRedactionClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WOORedactionService(
			$this->appManager,
			$this->filinq,
			$this->logger,
		);
	}//end setUp()

	/**
	 * IsDocuDeskInstalled returns false when the app is not installed.
	 *
	 * @return void
	 */
	public function testIsDocuDeskInstalledReturnsFalseWhenNotInstalled(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$this->assertFalse($this->service->isDocuDeskInstalled());
	}//end testIsDocuDeskInstalledReturnsFalseWhenNotInstalled()

	/**
	 * IsDocuDeskInstalled returns true when the app is installed and enabled.
	 *
	 * @return void
	 */
	public function testIsDocuDeskInstalledReturnsTrueWhenAvailable(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$this->assertTrue($this->service->isDocuDeskInstalled());
	}//end testIsDocuDeskInstalledReturnsTrueWhenAvailable()

	/**
	 * QueueForRedaction returns manual mode when Docudesk is not installed.
	 *
	 * Acceptance criterion: Docudesk not installed → UI falls back to manual upload flow.
	 *
	 * @return void
	 */
	public function testQueueForRedactionFallsBackToManualWhenNoDocuDesk(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		// The assertion that matters on this branch is the ABSENCE of the call:
		// an instance without filinq must not reach for it.
		$this->filinq->expects($this->never())->method('redact');

		$documents = [
			['id' => 'doc-001', 'title' => 'Vergunning A'],
			['id' => 'doc-002', 'title' => 'Rapport B'],
		];

		$result = $this->service->queueForRedaction('case-uuid-001', $documents);

		$this->assertSame('manual', $result['mode']);
		$this->assertEmpty($result['queued']);
		$this->assertCount(2, $result['manual']);
		$this->assertSame('awaiting_manual_redaction', $result['manual'][0]['status']);
	}//end testQueueForRedactionFallsBackToManualWhenNoDocuDesk()

	/**
	 * QueueForRedaction hands every document to filinq when filinq is installed.
	 *
	 * ⚠️ THIS TEST REPLACES ONE THAT COULD NOT FAIL. Its predecessor asserted
	 * `$result['queued'][0]['status'] === 'queued'` against a method that made
	 * no call at all: the status was a literal the method wrote itself, so the
	 * assertion held for as long as the integration was a no-op, which was its
	 * whole life. The assertion here is on the CALL. Break the wiring and this
	 * reddens on the invocation count, not on a returned string.
	 *
	 * @return void
	 */
	public function testQueueForRedactionCallsFilinqOncePerDocument(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$documents = [
			['id' => 'doc-001', 'fileId' => 41, 'title' => 'Rapport C'],
			['id' => 'doc-002', 'fileId' => 42, 'title' => 'Rapport D'],
		];

		$seen = [];
		$this->filinq->expects($this->exactly(2))
			->method('redact')
			->willReturnCallback(
				function (string $caseId, array $document) use (&$seen): array {
					$seen[] = [$caseId, $document['fileId']];
					return ['status' => 'redacted', 'sourceFileId' => $document['fileId'], 'entityCount' => 3];
				}
			);

		$result = $this->service->queueForRedaction('case-uuid-001', $documents);

		$this->assertSame([['case-uuid-001', 41], ['case-uuid-001', 42]], $seen);
		$this->assertSame('filinq', $result['mode']);
		$this->assertCount(2, $result['redacted']);
		$this->assertSame('redacted', $result['redacted'][0]['status']);
		$this->assertSame('doc-001', $result['redacted'][0]['documentId']);
		$this->assertEmpty($result['manual']);
	}//end testQueueForRedactionCallsFilinqOncePerDocument()

	/**
	 * A document filinq refuses is reported as needing manual redaction.
	 *
	 * The refusal must not be dressed as a success, and it must not take the
	 * other documents down with it.
	 *
	 * @return void
	 */
	public function testARefusedDocumentFallsToManualWithItsReason(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$this->filinq->method('redact')->willReturnCallback(
			function (string $caseId, array $document): array {
				if ($document['id'] === 'doc-bad') {
					throw new RuntimeException('file_unresolved: no such file');
				}

				return ['status' => 'redacted', 'sourceFileId' => 7, 'entityCount' => 1];
			}
		);

		$result = $this->service->queueForRedaction(
			'case-uuid-001',
			[['id' => 'doc-bad'], ['id' => 'doc-good', 'fileId' => 7]]
		);

		$this->assertCount(1, $result['redacted']);
		$this->assertSame('doc-good', $result['redacted'][0]['documentId']);
		$this->assertCount(1, $result['manual']);
		$this->assertSame('doc-bad', $result['manual'][0]['documentId']);
		$this->assertSame('awaiting_manual_redaction', $result['manual'][0]['status']);
		$this->assertStringContainsString('file_unresolved', $result['manual'][0]['reason']);
	}//end testARefusedDocumentFallsToManualWithItsReason()

	/**
	 * A document filinq removed nothing from is not counted as redacted.
	 *
	 * @return void
	 */
	public function testADocumentWithNoDetectedEntitiesFallsToManual(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->filinq->method('redact')->willReturn(
			['status' => 'no_entities_detected', 'sourceFileId' => 55, 'entityCount' => 0]
		);

		$result = $this->service->queueForRedaction('case-uuid-001', [['id' => 'doc-1', 'fileId' => 55]]);

		$this->assertEmpty($result['redacted'], 'a run that removed nothing is not a redaction');
		$this->assertCount(1, $result['manual']);
		$this->assertSame('awaiting_manual_redaction', $result['manual'][0]['status']);
		$this->assertSame('filinq_detected_no_entities', $result['manual'][0]['reason']);
	}//end testADocumentWithNoDetectedEntitiesFallsToManual()

	/**
	 * A run that produced no redacted file falls to manual, and says so.
	 *
	 * The two ways filinq can run and leave the document as it was need
	 * different repairs: nothing detected points at the detection backend,
	 * nothing produced points at the redaction pass. One shared sentence would
	 * send a Woo officer to the wrong half.
	 *
	 * @return void
	 */
	public function testADocumentWithNoProducedFileFallsToManualWithItsOwnReason(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->filinq->method('redact')->willReturn(
			[
				'status' => 'no_output_produced',
				'sourceFileId' => 55,
				'entityCount' => 3,
				'anonymizedFileId' => null,
			]
		);

		$result = $this->service->queueForRedaction('case-uuid-001', [['id' => 'doc-1', 'fileId' => 55]]);

		$this->assertEmpty($result['redacted'], 'no file produced is not a redaction');
		$this->assertCount(1, $result['manual']);
		$this->assertSame('filinq_produced_no_redacted_file', $result['manual'][0]['reason']);
	}//end testADocumentWithNoProducedFileFallsToManualWithItsOwnReason()

	/**
	 * Nothing is ever reported as queued, on any branch.
	 *
	 * `queued` was the status the no-op invented, and it is the one word that
	 * made the defect invisible: a queue implies something will drain it, and
	 * nothing ever did. The key stays in the response shape for callers that
	 * read it, and it stays empty.
	 *
	 * @return void
	 */
	public function testNothingIsEverReportedAsQueued(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->filinq->method('redact')->willReturn(['status' => 'redacted', 'sourceFileId' => 9]);

		$result = $this->service->queueForRedaction('case-uuid-001', [['id' => 'doc-001', 'fileId' => 9]]);

		$this->assertSame([], $result['queued']);
	}//end testNothingIsEverReportedAsQueued()

	/**
	 * QueueForRedaction returns empty result for empty document list.
	 *
	 * @return void
	 */
	public function testQueueForRedactionReturnsEmptyForNoDocuments(): void {
		$result = $this->service->queueForRedaction('case-uuid-001', []);

		$this->assertSame('none', $result['mode']);
		$this->assertEmpty($result['queued']);
		$this->assertEmpty($result['manual']);
	}//end testQueueForRedactionReturnsEmptyForNoDocuments()

	/**
	 * A document record with no identifier at all is skipped, not sent.
	 *
	 * @return void
	 */
	public function testADocumentWithoutAnIdentifierIsSkipped(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->filinq->expects($this->never())->method('redact');

		$result = $this->service->queueForRedaction('case-uuid-001', [['title' => 'no id here']]);

		$this->assertEmpty($result['redacted']);
		$this->assertEmpty($result['manual']);
	}//end testADocumentWithoutAnIdentifierIsSkipped()

}//end class
