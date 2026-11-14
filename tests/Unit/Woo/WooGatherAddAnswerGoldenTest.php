<?php

/**
 * WooGatherAdd answer golden tests.
 *
 * Pins the full answer row of every way a pick can end, what each path hands
 * to its collaborators (the collector, the record store, the exclusion list,
 * the access guard and the log), and the provenance a pick carries.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\CaseFileCollector;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooGatherAdd;
use OCA\Dossiq\Woo\WooSources;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for WooGatherAdd::addPicks().
 *
 * @covers \OCA\Dossiq\Woo\WooGatherAdd
 * @uses \OCA\Dossiq\Woo\WooPickRun
 * @uses \OCA\Dossiq\Woo\WooPickedFile
 */
class WooGatherAddAnswerGoldenTest extends TestCase {

	/**
	 * @var IRootFolder&MockObject
	 */
	private $rootFolder;

	/**
	 * @var DocumentRecordStore&MockObject
	 */
	private $store;

	/**
	 * @var CaseFileCollector&MockObject
	 */
	private $collector;

	/**
	 * @var WooSources&MockObject
	 */
	private $sources;

	/**
	 * @var CaseAccessGuard&MockObject
	 */
	private $accessGuard;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var WooCollection&MockObject
	 */
	private $collection;

	/**
	 * @var IUser&MockObject
	 */
	private $user;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->collector = $this->createMock(CaseFileCollector::class);
		$this->sources = $this->createMock(WooSources::class);
		$this->accessGuard = $this->createMock(CaseAccessGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->collection = $this->createMock(WooCollection::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('anna');
	}//end setUp()

	/**
	 * The service over the doubles; the sentences come back marked.
	 *
	 * @return WooGatherAdd
	 */
	private function service(): WooGatherAdd {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '['.$text.']');

		return new WooGatherAdd(
			$this->rootFolder,
			$this->store,
			$this->collector,
			$this->sources,
			$this->accessGuard,
			$l10n,
			$this->logger,
			$this->collection
		);
	}//end service()

	/**
	 * Add picks to case-1, found with "budget", over the plan custodian piet.
	 *
	 * @param array<int, mixed> $picks The picks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function add(array $picks): array {
		return $this->service()->addPicks('case-1', $picks, 'budget', $this->user, ['piet']);
	}//end add()

	/**
	 * The caller's folder holds one readable file, id 7.
	 *
	 * @param bool $readable Whether the caller may read it.
	 *
	 * @return void
	 */
	private function folderHoldsFileSeven(bool $readable = true): void {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getName')->willReturn('nota.pdf');
		$file->method('getContent')->willReturn('bytes');

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(static fn (int $id): array => ($id === 7 ? [$folder, $file] : []));
		$this->rootFolder->method('getUserFolder')->with('anna')->willReturn($folder);
	}//end folderHoldsFileSeven()

	/**
	 * A file pick of custodian piet.
	 *
	 * @param string $key The file id.
	 *
	 * @return array<string, string>
	 */
	private static function filePick(string $key = '7'): array {
		return ['source' => WooSources::SOURCE_FILES, 'key' => ' '.$key.' ', 'custodian' => ' piet ', 'location' => '/Budget'];
	}//end filePick()

	/**
	 * An added file answers in full, and the collector gets the bytes with the provenance.
	 *
	 * @return void
	 */
	public function testAnAddedFileAnswersInFull(): void {
		$this->folderHoldsFileSeven();
		$this->collection->method('duplicateOf')->with('case-1', hash('sha256', 'bytes'))->willReturn('');
		$collected = [];
		$this->collector->expects($this->once())->method('collect')->willReturnCallback(
			function (string $caseId, string $fileName, string $content, array $provenance, string $mimeType) use (&$collected): string {
				$collected = [$caseId, $fileName, $content, $provenance, $mimeType];
				return 'doc-1';
			}
		);

		$results = $this->add([self::filePick(), 'not a pick']);

		$this->assertSame(
			[['key' => '7', 'source' => WooSources::SOURCE_FILES, 'status' => 'added', 'documentId' => 'doc-1', 'reason' => '', 'message' => '']],
			$results
		);
		$this->assertSame(['case-1', 'nota.pdf', 'bytes'], array_slice($collected, 0, 3));
		$this->assertSame('', $collected[4]);
		$provenance = $collected[3];
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $provenance['searchedAt']);
		unset($provenance['searchedAt']);
		$this->assertSame(
			['source' => 'files', 'sourceSystem' => 'files', 'custodian' => 'piet', 'location' => '/Budget', 'terms' => 'budget', 'searchedBy' => 'anna'],
			$provenance
		);
	}//end testAnAddedFileAnswersInFull()

	/**
	 * A pick without a plan custodian is refused before anything is read.
	 *
	 * @return void
	 */
	public function testACustodianOutsideThePlanIsRefused(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->collection->expects($this->never())->method('record');

		$results = $this->add([['source' => 'files', 'key' => '7', 'custodian' => 'kees']]);

		$this->assertSame(
			[[
				'key' => '7',
				'source' => 'files',
				'status' => 'refused',
				'documentId' => '',
				'reason' => 'custodian-required',
				'message' => '[Pick whose files this document comes from, as the search plan names them.]',
			]],
			$results
		);
	}//end testACustodianOutsideThePlanIsRefused()

	/**
	 * A file the caller cannot read is refused and listed as unreadable.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsRefusedAndListed(): void {
		$this->folderHoldsFileSeven(readable: false);
		$this->collection->expects($this->once())->method('record')->with(
			'case-1',
			['source' => 'files', 'location' => '/Budget', 'custodian' => 'piet', 'reason' => 'unreadable', 'excludedBy' => 'anna']
		);

		$results = $this->add([self::filePick()]);

		$this->assertSame('refused', $results[0]['status']);
		$this->assertSame('not-readable', $results[0]['reason']);
		$this->assertSame('[You cannot read this document, so it cannot be added.]', $results[0]['message']);
	}//end testAnUnreadableFileIsRefusedAndListed()

	/**
	 * A file id of zero is refused without opening the folder.
	 *
	 * @return void
	 */
	public function testAFileIdOfZeroIsRefused(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertSame('not-readable', $this->add([self::filePick(key: 'x')])[0]['reason']);
	}//end testAFileIdOfZeroIsRefused()

	/**
	 * Bytes the case already holds are listed as a duplicate and excluded.
	 *
	 * @return void
	 */
	public function testADuplicateIsExcludedAndListed(): void {
		$this->folderHoldsFileSeven();
		$this->collection->method('duplicateOf')->willReturn('doc-0');
		$this->collector->expects($this->never())->method('collect');
		$this->collection->expects($this->once())->method('record')->with(
			'case-1',
			[
				'source' => 'files',
				'location' => '/Budget',
				'fileName' => 'nota.pdf',
				'sha256' => hash('sha256', 'bytes'),
				'duplicateOf' => 'doc-0',
				'custodian' => 'piet',
				'reason' => 'duplicate',
				'excludedBy' => 'anna',
			]
		);

		$results = $this->add([self::filePick()]);

		$this->assertSame('excluded', $results[0]['status']);
		$this->assertSame('', $results[0]['documentId']);
		$this->assertSame('[The case already holds this document. It is listed as a duplicate.]', $results[0]['message']);
	}//end testADuplicateIsExcludedAndListed()

	/**
	 * A collector that writes no record is a write failure, not listed.
	 *
	 * @return void
	 */
	public function testACollectorWithoutARecordIsAWriteFailure(): void {
		$this->folderHoldsFileSeven();
		$this->collection->method('duplicateOf')->willReturn('');
		$this->collector->method('collect')->willReturn('');
		$this->collection->expects($this->never())->method('record');

		$results = $this->add([self::filePick()]);

		$this->assertSame('write-failed', $results[0]['reason']);
		$this->assertSame('[This document could not be added to the case.]', $results[0]['message']);
	}//end testACollectorWithoutARecordIsAWriteFailure()

	/**
	 * Any other failure is logged and answered as a write failure.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsLogged(): void {
		$this->folderHoldsFileSeven();
		$this->collection->method('duplicateOf')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'WooGatherAdd: a pick could not be added',
			['case' => 'case-1', 'source' => 'files', 'error' => 'register down']
		);

		$results = $this->add([self::filePick()]);

		$this->assertSame('refused', $results[0]['status']);
		$this->assertSame('write-failed', $results[0]['reason']);
	}//end testAnUnexpectedFailureIsLogged()

	/**
	 * An integriq hit is fetched as the caller and stored with its MIME type.
	 *
	 * @return void
	 */
	public function testAFetchedHitKeepsItsMimeType(): void {
		$this->sources->expects($this->once())->method('fetchMicrosoft365')->with('h-1', 'anna')
			->willReturn(['fileName' => 'mail.eml', 'content' => 'eml', 'mimeType' => 'message/rfc822']);
		$this->collection->method('duplicateOf')->willReturn('');
		$this->collector->expects($this->once())->method('collect')
			->with('case-1', 'mail.eml', 'eml', $this->anything(), 'message/rfc822')
			->willReturn('doc-2');

		$results = $this->add([['source' => WooSources::SOURCE_MICROSOFT365, 'key' => 'h-1', 'custodian' => 'piet']]);

		$this->assertSame('added', $results[0]['status']);
		$this->assertSame('doc-2', $results[0]['documentId']);
	}//end testAFetchedHitKeepsItsMimeType()

	/**
	 * A hit integriq does not hand over is refused and listed as unreadable.
	 *
	 * @return void
	 */
	public function testAHitNotHandedOverIsListed(): void {
		$this->sources->method('fetchMicrosoft365')->willReturn(null);
		$this->collection->expects($this->once())->method('record')
			->with('case-1', $this->callback(static fn (array $fields): bool => $fields['reason'] === 'unreadable' && $fields['location'] === ''));

		$results = $this->add([['source' => WooSources::SOURCE_MICROSOFT365, 'key' => 'h-1', 'custodian' => 'piet']]);

		$this->assertSame('not-fetched', $results[0]['reason']);
		$this->assertSame('[The source did not hand over this document. Try again later.]', $results[0]['message']);
	}//end testAHitNotHandedOverIsListed()

	/**
	 * An unknown source is refused and not listed.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceIsRefused(): void {
		$this->collection->expects($this->never())->method('record');

		$results = $this->add([['source' => 'fax', 'key' => '1', 'custodian' => 'piet']]);

		$this->assertSame(['key' => '1', 'source' => 'fax', 'status' => 'refused', 'documentId' => '', 'reason' => 'unknown-source', 'message' => '[This source is not known.]'], $results[0]);
	}//end testAnUnknownSourceIsRefused()

	/**
	 * Another case's document is linked when the caller may read one of its cases.
	 *
	 * @return void
	 */
	public function testALinkIsAllowedThroughAReadableCase(): void {
		$this->store->method('findRecord')->with('doc-9')->willReturn(['id' => 'doc-9']);
		$this->store->method('joinsFor')->with('doc-9')->willReturn([['case' => ''], ['case' => 'case-2'], ['case' => 'case-3']]);
		$this->accessGuard->method('hasCaseReadAccess')->willReturnCallback(
			fn (string $caseId, IUser $user): bool => $caseId === 'case-3' && $user === $this->user
		);
		$joined = [];
		$this->store->expects($this->once())->method('ensureJoin')->willReturnCallback(
			function (string $caseId, string $recordId, array $extra) use (&$joined): bool {
				$joined = [$caseId, $recordId, array_keys($extra), $extra['provenance']['custodian']];
				return true;
			}
		);

		$results = $this->add([['source' => WooSources::SOURCE_CASES, 'key' => 'doc-9', 'custodian' => 'piet']]);

		$this->assertSame('added', $results[0]['status']);
		$this->assertSame('doc-9', $results[0]['documentId']);
		$this->assertSame(['case-1', 'doc-9', ['provenance'], 'piet'], $joined);
	}//end testALinkIsAllowedThroughAReadableCase()

	/**
	 * A document on no case the caller may read is refused and not joined.
	 *
	 * @return void
	 */
	public function testALinkWithoutAReadableCaseIsRefused(): void {
		$this->store->method('findRecord')->willReturn(['id' => 'doc-9']);
		$this->store->method('joinsFor')->willReturn([['case' => 'case-2']]);
		$this->accessGuard->method('hasCaseReadAccess')->willReturn(false);
		$this->store->expects($this->never())->method('ensureJoin');
		$this->collection->expects($this->once())->method('record')
			->with('case-1', $this->callback(static fn (array $fields): bool => $fields['reason'] === 'unreadable'));

		$results = $this->add([['source' => WooSources::SOURCE_CASES, 'key' => 'doc-9', 'custodian' => 'piet']]);

		$this->assertSame('not-readable', $results[0]['reason']);
	}//end testALinkWithoutAReadableCaseIsRefused()

	/**
	 * A document already on this case is refused before access is asked further.
	 *
	 * @return void
	 */
	public function testALinkAlreadyOnTheCaseIsRefused(): void {
		$this->store->method('findRecord')->willReturn(['id' => 'doc-9']);
		$this->store->method('joinsFor')->willReturn([['case' => 'case-1'], ['case' => 'case-2']]);
		$this->accessGuard->expects($this->never())->method('hasCaseReadAccess');
		$this->store->expects($this->never())->method('ensureJoin');
		$this->collection->expects($this->never())->method('record');

		$results = $this->add([['source' => WooSources::SOURCE_CASES, 'key' => 'doc-9', 'custodian' => 'piet']]);

		$this->assertSame('already-on-case', $results[0]['reason']);
		$this->assertSame('[This document is already on the case.]', $results[0]['message']);
	}//end testALinkAlreadyOnTheCaseIsRefused()

	/**
	 * An empty or unknown document key is refused as not found.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentIsNotFound(): void {
		$this->store->method('findRecord')->willReturn(null);
		$this->store->expects($this->never())->method('joinsFor');

		$results = $this->add([
			['source' => WooSources::SOURCE_CASES, 'key' => '', 'custodian' => 'piet'],
			['source' => WooSources::SOURCE_CASES, 'key' => 'gone', 'custodian' => 'piet'],
		]);

		$this->assertSame(['not-found', 'not-found'], array_column($results, 'reason'));
		$this->assertSame('[This document no longer exists.]', $results[0]['message']);
	}//end testAnUnknownDocumentIsNotFound()
}//end class
