<?php

/**
 * Dossier ZIP exports: which documents go in, and how the archive is built.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DossierZipExporter;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZipManifestBuilder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DossierZipExporter
 */
class DossierZipExporterTest extends TestCase {

	/**
	 * The dossier orchestrator.
	 *
	 * @var ZaakdossierService&MockObject
	 */
	private ZaakdossierService&MockObject $files;

	/**
	 * The ZIP builder.
	 *
	 * @var ZipManifestBuilder&MockObject
	 */
	private ZipManifestBuilder&MockObject $zip;

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = $this->createMock(ZaakdossierService::class);
		$this->zip   = $this->createMock(ZipManifestBuilder::class);
	}//end setUp()

	/**
	 * The exporter under test.
	 *
	 * @return DossierZipExporter
	 */
	private function exporter(): DossierZipExporter {
		return new DossierZipExporter($this->files, $this->zip, $this->createMock(LoggerInterface::class));
	}//end exporter()

	/**
	 * Without a selection every document of the case's dossier is exported.
	 *
	 * @return void
	 */
	public function testWithoutASelectionEveryDocumentIsExported(): void {
		$docs = [['id' => 'd1'], ['id' => 'd2']];
		$this->files->expects($this->once())->method('getDossierForCase')->with('case-1')
			->willReturn(['informatieobjecten' => $docs]);

		self::assertSame($docs, $this->exporter()->collectDocuments('case-1', []));
	}//end testWithoutASelectionEveryDocumentIsExported()

	/**
	 * A selection narrows to those ids, compared as strings, as a list without gaps.
	 *
	 * @return void
	 */
	public function testASelectionNarrowsToAListOfThoseDocuments(): void {
		$this->files->method('getDossierForCase')->willReturn(
			['informatieobjecten' => [['id' => 'd1'], ['id' => '2'], ['title' => 'no id'], ['id' => 'd3']]]
		);

		self::assertSame(
			[['id' => '2'], ['id' => 'd3']],
			$this->exporter()->collectDocuments('case-1', [2, 'd3', 'unknown'])
		);
	}//end testASelectionNarrowsToAListOfThoseDocuments()

	/**
	 * A dossier without a document list exports nothing.
	 *
	 * @return void
	 */
	public function testADossierWithoutDocumentsExportsNothing(): void {
		$this->files->method('getDossierForCase')->willReturnOnConsecutiveCalls([], ['informatieobjecten' => 'oops']);

		self::assertSame([], $this->exporter()->collectDocuments('case-1', []));
		self::assertSame([], $this->exporter()->collectDocuments('case-1', ['d1']));
	}//end testADossierWithoutDocumentsExportsNothing()

	/**
	 * The archive is built per type by default, flat on request, and its bytes returned.
	 *
	 * @return void
	 */
	public function testTheArchiveBytesAreReturnedForEitherLayout(): void {
		$user    = $this->createMock(IUser::class);
		$docs    = [['id' => 'd1']];
		$layouts = [];
		$paths   = [];
		$this->zip->expects($this->exactly(2))->method('buildZip')->willReturnCallback(
			static function (string $targetPath, ?IUser $who, array $documents, string $layout) use ($user, $docs, &$layouts, &$paths): array {
				self::assertSame($user, $who);
				self::assertSame($docs, $documents);
				file_put_contents($targetPath, 'PK-' . $layout);
				$layouts[] = $layout;
				$paths[]   = $targetPath;

				return [];
			}
		);

		self::assertSame('PK-per-type', $this->exporter()->buildZipData($user, $docs, false));
		self::assertSame('PK-flat', $this->exporter()->buildZipData($user, $docs, true));
		self::assertSame([ZipManifestBuilder::LAYOUT_PER_TYPE, ZipManifestBuilder::LAYOUT_FLAT], $layouts);
		foreach ($paths as $path) {
			self::assertFileDoesNotExist($path);
		}
	}//end testTheArchiveBytesAreReturnedForEitherLayout()

	/**
	 * A failing build propagates and still removes the temp file.
	 *
	 * @return void
	 */
	public function testAFailingBuildPropagatesAndCleansUp(): void {
		$path = null;
		$this->zip->method('buildZip')->willReturnCallback(
			static function (string $targetPath) use (&$path): array {
				$path = $targetPath;
				throw new RuntimeException('zip failed');
			}
		);

		try {
			$this->exporter()->buildZipData($this->createMock(IUser::class), [], true);
			self::fail('Expected the build failure to propagate');
		} catch (RuntimeException $e) {
			self::assertSame('zip failed', $e->getMessage());
		}

		self::assertIsString($path);
		self::assertFileDoesNotExist($path);
	}//end testAFailingBuildPropagatesAndCleansUp()
}//end class
