<?php

/**
 * Case Document Provenance Test
 *
 * The provenance of documents collected onto a case, read and filtered the
 * same way for any case type, over the real record store and the real case
 * documents on one in-memory register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance
 * @uses   \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CaseDocumentProvenanceTest extends TestCase {

	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	private const OTHER_CASE = '33333333-3333-4333-8333-333333333333';

	private InMemoryRegister $register;

	private CaseDocumentProvenance $provenance;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);

		$this->provenance = new CaseDocumentProvenance(
			store: new DocumentRecordStore(settingsService: $settings),
			caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: $this->createMock(LoggerInterface::class)),
		);
	}//end setUp()

	/**
	 * A copied file: provenance on its own record.
	 *
	 * @param string               $id         The document uuid.
	 * @param array<string, mixed> $provenance The provenance.
	 *
	 * @return void
	 */
	private function copied(string $id, array $provenance): void {
		$this->register->seed('informatieobject', $id, ['titel' => $id, 'provenance' => $provenance]);
		$this->register->seed('zaakinformatieobject', 'join-' . $id, ['case' => self::CASE_ID, 'informatieobject' => $id]);
	}//end copied()

	/**
	 * REQ-DPV-001: a copied file answers its record's provenance with every field present.
	 *
	 * @return void
	 */
	public function testACopiedFileAnswersItsRecordsProvenance(): void {
		$this->copied(id: 'doc-1', provenance: ['source' => 'files', 'sourceSystem' => 'files', 'custodian' => 'Team Verkeer', 'location' => '/Verkeer/notitie.pdf']);

		$row = $this->provenance->forDocument(caseId: self::CASE_ID, documentId: 'doc-1');

		self::assertSame(CaseDocumentProvenance::FIELDS, array_keys($row));
		self::assertSame('Team Verkeer', $row['custodian']);
		self::assertSame('files', $row['sourceSystem']);
		self::assertSame('/Verkeer/notitie.pdf', $row['location']);
		self::assertSame('', $row['terms'], 'a field never stored answers empty, not missing');
	}//end testACopiedFileAnswersItsRecordsProvenance()

	/**
	 * REQ-DPV-001: a document linked from another case answers this case's join, not the other one's.
	 *
	 * @return void
	 */
	public function testALinkedDocumentAnswersThisCasesJoin(): void {
		$this->register->seed('informatieobject', 'doc-2', ['titel' => 'shared']);
		$this->register->seed('zaakinformatieobject', 'join-other', ['case' => self::OTHER_CASE, 'informatieobject' => 'doc-2', 'provenance' => ['custodian' => 'Elsewhere']]);
		$this->register->seed('zaakinformatieobject', 'join-here', ['case' => self::CASE_ID, 'informatieobject' => 'doc-2', 'provenance' => ['source' => 'cases', 'custodian' => 'Wethouder Ruimte']]);

		$row = $this->provenance->forDocument(caseId: self::CASE_ID, documentId: 'doc-2');

		self::assertSame('Wethouder Ruimte', $row['custodian']);
		self::assertSame('cases', $row['sourceSystem'], 'sourceSystem falls back to source when it was never recorded');
	}//end testALinkedDocumentAnswersThisCasesJoin()

	/**
	 * REQ-DPV-001: a document added by hand carries no provenance and answers empty fields.
	 *
	 * @return void
	 */
	public function testADocumentWithoutProvenanceAnswersEmptyFields(): void {
		$this->register->seed('informatieobject', 'doc-3', ['titel' => 'by hand']);
		$this->register->seed('zaakinformatieobject', 'join-doc-3', ['case' => self::CASE_ID, 'informatieobject' => 'doc-3']);

		$row = $this->provenance->forDocument(caseId: self::CASE_ID, documentId: 'doc-3');

		self::assertSame(array_fill_keys(CaseDocumentProvenance::FIELDS, ''), $row);
	}//end testADocumentWithoutProvenanceAnswersEmptyFields()

	/**
	 * REQ-DPV-002: documents are selected by custodian and system together.
	 *
	 * @return void
	 */
	public function testDocumentsAreSelectedByCustodianAndSystem(): void {
		$this->copied(id: 'doc-a', provenance: ['source' => 'files', 'custodian' => 'A']);
		$this->copied(id: 'doc-b', provenance: ['source' => 'microsoft365', 'sourceSystem' => 'sharepoint', 'custodian' => 'A']);
		$this->copied(id: 'doc-c', provenance: ['source' => 'files', 'custodian' => 'B']);

		self::assertSame(['doc-a', 'doc-b'], $this->provenance->select(caseId: self::CASE_ID, filter: ['custodian' => 'A']));
		self::assertSame(['doc-b'], $this->provenance->select(caseId: self::CASE_ID, filter: ['custodian' => 'A', 'sourceSystem' => 'sharepoint']));
		self::assertSame([], $this->provenance->select(caseId: self::CASE_ID, filter: ['custodian' => 'C']));
		self::assertSame(['doc-a', 'doc-b', 'doc-c'], $this->provenance->select(caseId: self::CASE_ID, filter: []));
	}//end testDocumentsAreSelectedByCustodianAndSystem()

	/**
	 * REQ-DPV-002: a filter on a field provenance does not carry is refused, never an empty answer.
	 *
	 * @return void
	 */
	public function testAFilterOnAnUnknownFieldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->provenance->select(caseId: self::CASE_ID, filter: ['department' => 'X']);
	}//end testAFilterOnAnUnknownFieldIsRefused()

	/**
	 * REQ-DPV-002: the distinct values of a field, for a filter's options.
	 *
	 * @return void
	 */
	public function testValuesOfAFieldAreDistinctAndSorted(): void {
		$this->copied(id: 'doc-a', provenance: ['source' => 'files', 'custodian' => 'Team Verkeer']);
		$this->copied(id: 'doc-b', provenance: ['source' => 'files', 'custodian' => 'Afdeling Vergunningen']);
		$this->copied(id: 'doc-c', provenance: ['source' => 'files', 'custodian' => 'Team Verkeer']);
		$this->copied(id: 'doc-d', provenance: []);

		self::assertSame(['Afdeling Vergunningen', 'Team Verkeer'], $this->provenance->valuesOf(caseId: self::CASE_ID, field: 'custodian'));
		self::assertSame(['files'], $this->provenance->valuesOf(caseId: self::CASE_ID, field: 'sourceSystem'));
		self::assertSame([], $this->provenance->valuesOf(caseId: self::CASE_ID, field: 'terms'), 'a field outside the filter fields has no options');
	}//end testValuesOfAFieldAreDistinctAndSorted()

	/**
	 * REQ-WRR-003: a mail answers its header fields lower-cased, with the sender's domain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function testAMailAnswersItsHeaderFields(): void {
		$this->copied(id: 'mail-1', provenance: ['source' => 'files', 'mail' => ['from' => 'A.Jansen@Gemeente.nl', 'to' => ['b@aannemer.nl'], 'cc' => 'C@gemeente.nl', 'date' => '2026-03-03T10:00:00+01:00', 'messageId' => '<m1@gemeente.nl>']]);

		$mail = $this->provenance->mailOf(caseId: self::CASE_ID, documentId: 'mail-1');

		self::assertTrue($mail['known']);
		self::assertSame('a.jansen@gemeente.nl', $mail['from']);
		self::assertSame('gemeente.nl', $mail['senderDomain']);
		self::assertSame(['b@aannemer.nl'], $mail['to']);
		self::assertSame(['c@gemeente.nl'], $mail['cc']);
		self::assertSame('<m1@gemeente.nl>', $mail['messageId']);
	}//end testAMailAnswersItsHeaderFields()

	/**
	 * REQ-WRR-003: a document without mail headers answers unknown, never a guessed sender.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function testADocumentWithoutMailHeadersAnswersUnknown(): void {
		$this->copied(id: 'doc-x', provenance: ['source' => 'files']);
		$this->copied(id: 'mail-u', provenance: ['mail' => ['unreadable' => true]]);

		$plain = $this->provenance->mailOf(caseId: self::CASE_ID, documentId: 'doc-x');
		self::assertFalse($plain['known']);
		self::assertSame('', $plain['senderDomain']);
		self::assertTrue($this->provenance->mailOf(caseId: self::CASE_ID, documentId: 'mail-u')['unreadable']);
	}//end testADocumentWithoutMailHeadersAnswersUnknown()
}//end class
