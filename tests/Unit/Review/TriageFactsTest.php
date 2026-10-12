<?php

/**
 * Tests for TriageFacts: what a triage rule reads about one document.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
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

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Review\TriageFacts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Review\TriageFacts
 * @uses   \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class TriageFactsTest extends TestCase {

	/**
	 * The facts over a register with one mail and one note on case X.
	 *
	 * @param DocumentText|null $text The text double, or one that answers nothing.
	 *
	 * @return TriageFacts
	 */
	private function facts(?DocumentText $text = null): TriageFacts {
		$store = new InMemoryRegister();
		$store->seed(schema: 'informatieobject', uuid: 'mail-1', row: ['format' => 'message/rfc822', 'creatiedatum' => '2026-01-01', 'fileId' => 5, 'provenance' => ['source' => 'exchange', 'custodian' => 'A. de Wit', 'mail' => ['from' => 'Redactie@Nieuwsbrief.example.nl', 'date' => 'Tue, 3 Mar 2026 10:00:00 +0100']]]);
		$store->seed(schema: 'informatieobject', uuid: 'note-1', row: ['mimeType' => 'application/pdf', 'creatiedatum' => '2025-06-01T09:00:00+02:00']);
		foreach (['mail-1', 'note-1'] as $id) {
			$store->seed(schema: 'zaakinformatieobject', uuid: 'join-'.$id, row: ['case' => 'case-x', 'informatieobject' => $id]);
		}

		$config = ['register' => 'dossiq', 'dossier_informatieobject_schema' => 'informatieobject', 'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$caseDocuments = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $logger);

		return new TriageFacts(
			caseDocuments: $caseDocuments,
			provenance: new CaseDocumentProvenance(store: new DocumentRecordStore(settingsService: $settings), caseDocuments: $caseDocuments),
			text: ($text ?? $this->createMock(DocumentText::class)),
			logger: $logger,
		);
	}//end facts()

	/**
	 * A mail reads its custodian, system, sender domain and the mail date over its creation date.
	 *
	 * @return void
	 */
	public function testAMailReadsProvenanceAndHeaders(): void {
		$facts = $this->facts()->forDocument(caseId: 'case-x', documentId: 'mail-1');

		$this->assertSame('A. de Wit', $facts['custodian']);
		$this->assertSame('exchange', $facts['sourceSystem']);
		$this->assertSame('message/rfc822', $facts['mimeType']);
		$this->assertSame('nieuwsbrief.example.nl', $facts['senderDomain']);
		$this->assertSame('2026-03-03', $facts['date'], 'the mail date wins over the creation date');
		$this->assertNull($facts['text'], 'the text is not read until a rule asks for it');
	}//end testAMailReadsProvenanceAndHeaders()

	/**
	 * A document without provenance or headers reads empty facts and its creation date.
	 *
	 * @return void
	 */
	public function testADocumentWithoutProvenanceReadsItsRecord(): void {
		$facts = $this->facts()->forDocument(caseId: 'case-x', documentId: 'note-1');

		$this->assertSame('', $facts['custodian']);
		$this->assertSame('', $facts['senderDomain']);
		$this->assertSame('application/pdf', $facts['mimeType']);
		$this->assertSame('2025-06-01', $facts['date']);
	}//end testADocumentWithoutProvenanceReadsItsRecord()

	/**
	 * The text is read from the record when asked.
	 *
	 * @return void
	 */
	public function testTheTextIsReadWhenAsked(): void {
		$text = $this->createMock(DocumentText::class);
		$text->expects($this->once())->method('extracted')->with($this->callback(static fn (array $record): bool => ($record['fileId'] ?? 0) === 5))->willReturn('Nieuwsbrief');
		$facts = $this->facts(text: $text);

		$this->assertSame('Nieuwsbrief', $facts->withText(facts: $facts->forDocument(caseId: 'case-x', documentId: 'mail-1'))['text']);
	}//end testTheTextIsReadWhenAsked()

	/**
	 * A provenance that cannot be read refuses, so no rule marks on half the facts.
	 *
	 * @return void
	 */
	public function testAnUnreadableProvenanceRefuses(): void {
		$provenance = $this->createMock(CaseDocumentProvenance::class);
		$provenance->method('forDocument')->willThrowException(new RuntimeException('schema not configured'));
		$caseDocuments = $this->createMock(WooCaseDocuments::class);
		$facts = new TriageFacts(caseDocuments: $caseDocuments, provenance: $provenance, text: $this->createMock(DocumentText::class), logger: $this->createMock(LoggerInterface::class));

		$this->expectException(RefusedException::class);
		$facts->forDocument(caseId: 'case-x', documentId: 'doc-1');
	}//end testAnUnreadableProvenanceRefuses()
}//end class
