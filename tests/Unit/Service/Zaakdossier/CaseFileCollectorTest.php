<?php

/**
 * Case File Collector Test
 *
 * Collected bytes become a document on the case with their provenance; a
 * collected mail also gets its header fields. The end-to-end path over the
 * real store and projection is in WooGatherAddTest; this test covers the
 * collector's own branches.
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

use OCA\Dossiq\Service\Zaakdossier\CaseFileCollector;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\MailHeaderReader;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\CaseFileCollector
 * @uses   \OCA\Dossiq\Service\Zaakdossier\MailHeaderReader
 */
class CaseFileCollectorTest extends TestCase {

	private const CASE_ID = 'case-1';

	private DocumentRecordStore&MockObject $store;

	private DocumentProjectionService&MockObject $projection;

	/**
	 * The records saved, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->projection = $this->createMock(DocumentProjectionService::class);
		$this->store->method('saveRecord')->willReturnCallback(
			function (array $record): string {
				$this->saved[] = $record;
				return 'record-1';
			}
		);
	}//end setUp()

	/**
	 * The collector over a mail header reader whose OpenRegister has no parser.
	 *
	 * @return CaseFileCollector The collector.
	 */
	private function collector(): CaseFileCollector {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		return new CaseFileCollector(
			store: $this->store,
			projection: $this->projection,
			mailHeaders: new MailHeaderReader(container: $container, logger: $this->createMock(LoggerInterface::class)),
		);
	}//end collector()

	/**
	 * A write that yields no file, or a file that yields no record, collects nothing.
	 *
	 * @return void
	 */
	public function testNothingIsCollectedWithoutAFileOrARecord(): void {
		$this->store->method('storeFileOnObject')->willReturnOnConsecutiveCalls(0, 5);
		$this->store->method('findRecord')->willReturn(null);
		$this->projection->method('folderOf')->willReturn(null);

		self::assertSame('', $this->collector()->collect(caseId: self::CASE_ID, fileName: 'a.pdf', content: 'x', provenance: []));
		self::assertSame('', $this->collector()->collect(caseId: self::CASE_ID, fileName: 'a.pdf', content: 'x', provenance: []));
		self::assertSame([], $this->saved);
	}//end testNothingIsCollectedWithoutAFileOrARecord()

	/**
	 * A file the listener did not project is projected here; the provenance lands on it and it joins the case.
	 *
	 * @return void
	 */
	public function testAnUnprojectedFileIsProjectedAndJoined(): void {
		$file = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(5)->willReturn([$file]);
		$this->store->method('storeFileOnObject')->willReturn(5);
		$this->store->method('findRecord')->willReturn(null);
		$this->projection->method('folderOf')->with(self::CASE_ID)->willReturn($folder);
		$this->projection->expects(self::once())->method('projectNode')->with($file)->willReturn(['title' => 'notulen']);
		$this->store->expects(self::once())->method('ensureJoin')->with(self::CASE_ID, 'record-1');

		$recordId = $this->collector()->collect(caseId: self::CASE_ID, fileName: 'notulen.pdf', content: 'x', provenance: ['source' => 'files']);

		self::assertSame('record-1', $recordId);
		self::assertSame(['title' => 'notulen', 'provenance' => ['source' => 'files']], $this->saved[0]);
	}//end testAnUnprojectedFileIsProjectedAndJoined()

	/**
	 * A mail, by name or by the source's MIME type, whose headers cannot be read is marked unreadable.
	 *
	 * @return void
	 */
	public function testAMailWhoseHeadersCannotBeReadIsMarked(): void {
		$this->store->method('storeFileOnObject')->willReturn(5);
		$this->store->method('findRecord')->willReturn(['title' => 'bericht']);
		$this->projection->method('folderOf')->willReturn(null);

		$this->collector()->collect(caseId: self::CASE_ID, fileName: 'bericht', content: 'x', provenance: ['source' => 'm'], mimeType: 'message/rfc822');
		$this->collector()->collect(caseId: self::CASE_ID, fileName: 'bericht.eml', content: 'x', provenance: ['source' => 'm']);

		self::assertSame(['unreadable' => true], $this->saved[0]['provenance']['mail']);
		self::assertSame(['unreadable' => true], $this->saved[1]['provenance']['mail']);
	}//end testAMailWhoseHeadersCannotBeReadIsMarked()
}//end class
