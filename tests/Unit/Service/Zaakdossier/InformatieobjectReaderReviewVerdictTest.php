<?php

/**
 * InformatieobjectReader review verdict unit tests.
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

use OCA\Dossiq\Review\DocumentVerdicts;
use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use PHPUnit\Framework\TestCase;

/**
 * The listing's rows carry the case's review verdict for their document.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader
 *
 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-each-document-row-carries-its-verdict
 */
class InformatieobjectReaderReviewVerdictTest extends TestCase {

	/**
	 * The reader over the given review verdicts.
	 *
	 * @param DocumentVerdicts|null $verdicts The review verdicts, or none.
	 *
	 * @return InformatieobjectReader
	 */
	private function reader(?DocumentVerdicts $verdicts): InformatieobjectReader {
		return new InformatieobjectReader(
			fileService: $this->createMock(ZaakdossierService::class),
			accessGuard: $this->createMock(InformatieobjectAccessGuard::class),
			documentService: $this->createMock(ZgwDocumentService::class),
			reviewVerdicts: $verdicts,
		);
	}//end reader()

	/**
	 * Each document carries its verdict, read once for the case; a document the
	 * verdicts do not name carries none.
	 *
	 * @return void
	 */
	public function testEachDocumentCarriesItsVerdictReadOnceForTheCase(): void {
		$verdicts = $this->createMock(DocumentVerdicts::class);
		$verdicts->expects($this->once())->method('byDocument')->with('case-1')->willReturn(['doc-1' => 'deels_openbaar']);

		$rows = $this->reader(verdicts: $verdicts)->withReviewVerdicts(caseId: 'case-1', documents: [['id' => 'doc-1'], ['id' => 'doc-2']]);

		self::assertSame([['id' => 'doc-1', 'reviewVerdict' => 'deels_openbaar'], ['id' => 'doc-2']], $rows);
	}//end testEachDocumentCarriesItsVerdictReadOnceForTheCase()

	/**
	 * Without the verdicts, or without rows, nothing is read and the rows pass through.
	 *
	 * @return void
	 */
	public function testWithoutVerdictsOrRowsTheRowsAreUnchanged(): void {
		self::assertSame([['id' => 'a']], $this->reader(verdicts: null)->withReviewVerdicts(caseId: 'case-1', documents: [['id' => 'a']]));

		$verdicts = $this->createMock(DocumentVerdicts::class);
		$verdicts->expects($this->never())->method('byDocument');
		self::assertSame([], $this->reader(verdicts: $verdicts)->withReviewVerdicts(caseId: 'case-1', documents: []));
	}//end testWithoutVerdictsOrRowsTheRowsAreUnchanged()
}//end class
