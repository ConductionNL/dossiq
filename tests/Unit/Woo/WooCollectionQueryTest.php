<?php

/**
 * Woo Collection Query Test
 *
 * A search run from a Woo case is stored with its result keys, and a
 * colleague re-runs it with their own access and sees what is new.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCollectionQueries;
use OCA\Dossiq\Woo\WooCorpusRefused;
use OCA\Dossiq\Woo\WooSources;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Woo\WooCollectionQueries
 */
class WooCollectionQueryTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	private InMemoryRegister $register;

	/**
	 * The files each user's search finds.
	 *
	 * @var array<string, array<int, File>>
	 */
	private array $files = [];

	private WooSources $sources;

	/**
	 * The service over the register and the file doubles.
	 *
	 * @return WooCollectionQueries The service.
	 */
	private function queries(): WooCollectionQueries {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_collection_query_schema' => 'wooCollectionQuery',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $userId): Folder {
				$folder = $this->createMock(Folder::class);
				$folder->method('search')->willReturn($this->files[$userId] ?? []);
				return $folder;
			}
		);

		return new WooCollectionQueries(
			settingsService: $settings,
			rootFolder: $root,
			sources: $this->sources,
			caseDocuments: new WooCaseDocuments(settingsService: $settings, rootFolder: $root, logger: $this->createMock(LoggerInterface::class)),
		);
	}//end queries()

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->sources = $this->createMock(WooSources::class);
	}//end setUp()

	/**
	 * A file double.
	 *
	 * @param int $id The file id.
	 *
	 * @return File The file.
	 */
	private function file(int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn('stationsweg-' . $id . '.pdf');
		$file->method('getPath')->willReturn('/abakker/files/Ruimte/stationsweg-' . $id . '.pdf');
		return $file;
	}//end file()

	public function testASearchIsStoredWithItsResultKeys(): void {
		$stored = $this->queries()->store(
			caseId: self::CASE_ID,
			search: ['source' => 'files', 'terms' => 'Stationsweg', 'periodFrom' => '2025-01-01', 'periodTo' => '2025-12-31', 'resultKeys' => [11, '12', '', 12]],
			userId: 'pjansen',
		);

		self::assertSame(['11', '12'], $stored['resultKeys']);
		self::assertSame('pjansen', $stored['runBy']);
		self::assertSame([], (new RealSchemaValidator())->errors(slug: 'wooCollectionQuery', payload: $stored));

		$this->expectException(WooCorpusRefused::class);
		$this->queries()->store(caseId: self::CASE_ID, search: ['source' => 'dropbox'], userId: 'pjansen');
	}//end testASearchIsStoredWithItsResultKeys()

	/**
	 * REQ-WRC-004 "A colleague re-runs the query and sees what is new".
	 *
	 * @return void
	 */
	public function testARerunMarksOnlyNewRows(): void {
		$stored = $this->queries()->store(
			caseId: self::CASE_ID,
			search: ['source' => 'files', 'terms' => 'Stationsweg', 'resultKeys' => range(1, 8)],
			userId: 'pjansen',
		);
		$this->files['abakker'] = array_map(fn (int $id): File => $this->file(id: $id), range(1, 10));

		$answer = $this->queries()->rerun(caseId: self::CASE_ID, queryId: $stored['id'], userId: 'abakker');

		self::assertCount(10, $answer['rows']);
		self::assertSame(['9', '10'], array_column(array_filter($answer['rows'], static fn (array $row): bool => $row['new']), 'key'));
		self::assertCount(10, $this->register->row('wooCollectionQuery', $stored['id'])['resultKeys'], 'the next re-run counts these as seen');

		$again = $this->queries()->rerun(caseId: self::CASE_ID, queryId: $stored['id'], userId: 'abakker');
		self::assertSame([], array_filter(array_column($again['rows'], 'new')));
	}//end testARerunMarksOnlyNewRows()

	public function testADocumentAlreadyOnTheCaseIsNotNew(): void {
		$this->register->seed('informatieobject', 'doc-1', ['title' => 'Advies']);
		$this->register->seed('informatieobject', 'doc-2', ['title' => 'Memo']);
		$this->register->seed('zaakinformatieobject', 'j-1', ['case' => self::CASE_ID, 'informatieobject' => 'doc-1']);
		$stored = $this->queries()->store(caseId: self::CASE_ID, search: ['source' => 'cases', 'terms' => 'Stationsweg'], userId: 'pjansen');

		$answer = $this->queries()->rerun(caseId: self::CASE_ID, queryId: $stored['id'], userId: 'abakker');

		self::assertSame(['doc-1' => false, 'doc-2' => true], array_column($answer['rows'], 'new', 'key'));
	}//end testADocumentAlreadyOnTheCaseIsNotNew()

	public function testAMicrosoft365RerunGoesThroughIntegriqAndPassesARefusalOn(): void {
		$this->sources->method('searchMicrosoft365')->willReturn(['rows' => [], 'remaining' => 0, 'notices' => [], 'refusal' => 'integriq-not-installed']);
		$stored = $this->queries()->store(caseId: self::CASE_ID, search: ['source' => 'microsoft365', 'terms' => 'x'], userId: 'pjansen');

		self::assertSame('integriq-not-installed', $this->queries()->rerun(caseId: self::CASE_ID, queryId: $stored['id'], userId: 'abakker')['refusal']);

		$this->expectException(WooCorpusRefused::class);
		$this->queries()->rerun(caseId: '22222222-2222-4222-8222-222222222222', queryId: $stored['id'], userId: 'abakker');
	}//end testAMicrosoft365RerunGoesThroughIntegriqAndPassesARefusalOn()
}//end class
