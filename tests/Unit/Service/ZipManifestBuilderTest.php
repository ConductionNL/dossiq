<?php

/**
 * ZipManifestBuilder Unit Tests
 *
 * Verifies the dossier ZIP export: manifest.csv columns/rows, per-type
 * sub-folders, clearance-based exclusion of documents above the caller's
 * level, and unique entry naming for duplicate filenames.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwDocumentService;
use OCA\Procest\Service\ZipManifestBuilder;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ZipManifestBuilder.
 *
 * @covers \OCA\Procest\Service\ZipManifestBuilder
 *
 * @uses \OCA\Procest\Service\InformatieobjectAccessGuard
 */
class ZipManifestBuilderTest extends TestCase {

	/**
	 * Mocked document storage service.
	 *
	 * @var ZgwDocumentService
	 */
	private ZgwDocumentService $documents;

	/**
	 * Builder under test.
	 *
	 * @var ZipManifestBuilder
	 */
	private ZipManifestBuilder $builder;

	/**
	 * Real guard wired with a configurable clearance.
	 *
	 * @var InformatieobjectAccessGuard
	 */
	private InformatieobjectAccessGuard $guard;

	/**
	 * Temp paths to clean up.
	 *
	 * @var string[]
	 */
	private array $tmpPaths = [];

	/**
	 * Set up fixtures with an intern-clearance guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$this->documents->method('getContent')->willReturnCallback(
			static fn (string $uuid, string $fileName) => 'content-of-' . $uuid
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([
			['dossier_default_clearance', 'intern', 'intern'],
			['dossier_clearance_group_map', '', ''],
		]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$this->guard = new InformatieobjectAccessGuard(
			settingsService: $settings,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);
		$this->builder = new ZipManifestBuilder(
			documentService: $this->documents,
			accessGuard: $this->guard,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Clean up temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tmpPaths as $path) {
			if (is_file($path) === true) {
				@unlink($path);
			}
		}

	}//end tearDown()

	/**
	 * Make an intern-clearance user.
	 *
	 * @return IUser
	 */
	private function internUser(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u');
		return $user;
	}//end internUser()

	/**
	 * buildManifest produces a header row and one row per document.
	 *
	 * @return void
	 */
	public function testBuildManifestColumnsAndRows(): void {
		$docs = [
			[
				'bestandsnaam' => 'a.pdf',
				'titel' => 'Doc A',
				'informatieobjecttype' => 'Advies',
				'status' => 'definitief',
				'vertrouwelijkheidaanduiding' => 'intern',
				'creatiedatum' => '2026-01-01',
				'auteur' => 'Jan',
			],
		];

		$csv = $this->builder->buildManifest($docs);
		$rows = array_filter(array_map('str_getcsv', explode("\n", trim($csv))));

		$this->assertSame(ZipManifestBuilder::MANIFEST_COLUMNS, $rows[0]);
		$this->assertSame('a.pdf', $rows[1][0]);
		$this->assertSame('Doc A', $rows[1][1]);
		$this->assertSame('Advies', $rows[1][2]);
		$this->assertSame('Jan', $rows[1][6]);

	}//end testBuildManifestColumnsAndRows()

	/**
	 * buildZip writes manifest.csv plus per-type sub-folders.
	 *
	 * @return void
	 */
	public function testBuildZipHasManifestAndTypeFolders(): void {
		$docs = [
			['id' => 'inf-1', 'bestandsnaam' => 'a.pdf', 'informatieobjecttype' => 'Advies', 'vertrouwelijkheidaanduiding' => 'openbaar'],
			['id' => 'inf-2', 'bestandsnaam' => 'b.pdf', 'informatieobjecttype' => 'Aanvraag', 'vertrouwelijkheidaanduiding' => 'intern'],
		];

		$path = (string)tempnam(sys_get_temp_dir(), 'ziptest-');
		$this->tmpPaths[] = $path;

		$result = $this->builder->buildZip($path, $this->internUser(), $docs, ZipManifestBuilder::LAYOUT_PER_TYPE);

		$this->assertSame(2, $result['included']);
		$this->assertSame(0, $result['excluded']);

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($path) === true);

		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}

		$this->assertContains('manifest.csv', $names);
		$this->assertContains('Advies/a.pdf', $names);
		$this->assertContains('Aanvraag/b.pdf', $names);
		$zip->close();

	}//end testBuildZipHasManifestAndTypeFolders()

	/**
	 * buildZip excludes documents above the caller's clearance.
	 *
	 * @return void
	 */
	public function testBuildZipExcludesAboveClearance(): void {
		$docs = [
			['id' => 'inf-1', 'bestandsnaam' => 'open.pdf', 'informatieobjecttype' => 'Aanvraag', 'vertrouwelijkheidaanduiding' => 'openbaar'],
			['id' => 'inf-2', 'bestandsnaam' => 'geheim.pdf', 'informatieobjecttype' => 'Advies', 'vertrouwelijkheidaanduiding' => 'geheim'],
			['id' => 'inf-3', 'bestandsnaam' => 'top.pdf', 'informatieobjecttype' => 'Advies', 'vertrouwelijkheidaanduiding' => 'zeer_geheim'],
		];

		$path = (string)tempnam(sys_get_temp_dir(), 'ziptest-');
		$this->tmpPaths[] = $path;

		// intern clearance: only the openbaar document is included.
		$result = $this->builder->buildZip($path, $this->internUser(), $docs, ZipManifestBuilder::LAYOUT_FLAT);

		$this->assertSame(1, $result['included']);
		$this->assertSame(2, $result['excluded']);

		$zip = new \ZipArchive();
		$zip->open($path);
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}

		$this->assertContains('open.pdf', $names);
		$this->assertNotContains('geheim.pdf', $names);
		$this->assertNotContains('top.pdf', $names);

		// manifest.csv reflects only the one included row (header + 1 data row).
		$manifest = $zip->getFromName('manifest.csv');
		$lines = array_filter(explode("\n", trim((string)$manifest)));
		$this->assertCount(2, $lines);
		$zip->close();

	}//end testBuildZipExcludesAboveClearance()

	/**
	 * Duplicate filenames are de-duplicated within the archive.
	 *
	 * @return void
	 */
	public function testBuildZipDeduplicatesFilenames(): void {
		$docs = [
			['id' => 'inf-1', 'bestandsnaam' => 'doc.pdf', 'informatieobjecttype' => 'Advies', 'vertrouwelijkheidaanduiding' => 'openbaar'],
			['id' => 'inf-2', 'bestandsnaam' => 'doc.pdf', 'informatieobjecttype' => 'Advies', 'vertrouwelijkheidaanduiding' => 'openbaar'],
		];

		$path = (string)tempnam(sys_get_temp_dir(), 'ziptest-');
		$this->tmpPaths[] = $path;

		$this->builder->buildZip($path, $this->internUser(), $docs, ZipManifestBuilder::LAYOUT_PER_TYPE);

		$zip = new \ZipArchive();
		$zip->open($path);
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name !== 'manifest.csv') {
				$names[] = $name;
			}
		}

		$this->assertCount(2, $names);
		$this->assertSame(count($names), count(array_unique($names)));
		$zip->close();

	}//end testBuildZipDeduplicatesFilenames()
}//end class
