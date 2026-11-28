<?php

/**
 * InformatieobjectReader scan verdict unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Documents\ScanVerdictReader;
use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use PHPUnit\Framework\TestCase;

/**
 * The listing's rows carry the scanner's verdict for their own file.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader
 *
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
class InformatieobjectReaderScanVerdictTest extends TestCase {

	/**
	 * Each row is read by its own file id; a row without one asks for id 0.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesTheVerdictOfItsFile(): void {
		$verdicts = $this->createMock(ScanVerdictReader::class);
		$verdicts->method('verdictFor')->willReturnCallback(
			static fn (int $fileId): array => ['state' => $fileId === 42 ? 'clean' : 'not-scanned']
		);

		$reader = new InformatieobjectReader(
			fileService: $this->createMock(ZaakdossierService::class),
			accessGuard: $this->createMock(InformatieobjectAccessGuard::class),
			documentService: $this->createMock(ZgwDocumentService::class),
			verdicts: $verdicts,
		);

		$rows = $reader->withScanVerdicts(documents: [['id' => 'a', 'fileId' => 42], ['id' => 'b']]);

		self::assertSame(['state' => 'clean'], $rows[0]['scanVerdict']);
		self::assertSame(['state' => 'not-scanned'], $rows[1]['scanVerdict']);
	}//end testEveryRowCarriesTheVerdictOfItsFile()

	/**
	 * Without the scanner reader the rows pass through unchanged.
	 *
	 * @return void
	 */
	public function testWithoutAReaderTheRowsAreUnchanged(): void {
		$reader = new InformatieobjectReader(
			fileService: $this->createMock(ZaakdossierService::class),
			accessGuard: $this->createMock(InformatieobjectAccessGuard::class),
			documentService: $this->createMock(ZgwDocumentService::class),
		);

		self::assertSame([['id' => 'a']], $reader->withScanVerdicts(documents: [['id' => 'a']]));
	}//end testWithoutAReaderTheRowsAreUnchanged()
}//end class
