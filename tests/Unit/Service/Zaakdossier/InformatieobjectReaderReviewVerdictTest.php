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
 * The listing's rows carry the case's review verdict and its grounds for their document.
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
			fileService: $this->createMock(originalClassName: ZaakdossierService::class),
			accessGuard: $this->createMock(originalClassName: InformatieobjectAccessGuard::class),
			documentService: $this->createMock(originalClassName: ZgwDocumentService::class),
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
		$verdicts = $this->createMock(originalClassName: DocumentVerdicts::class);
		$verdicts->expects($this->once())->method('byDocument')->with('case-1')->willReturn(['doc-1' => 'deels_openbaar', 'doc-3' => 'open']);
		$verdicts->expects($this->once())->method('groundsByDocument')->with('case-1')->willReturn(['doc-1' => ['5.1.2.e'], 'doc-3' => []]);

		$rows = $this->reader(verdicts: $verdicts)->withReviewVerdicts(
			caseId: 'case-1',
			documents: [['id' => 'doc-1'], ['id' => 'doc-2'], ['id' => 'doc-3']]
		);

		self::assertSame(
			expected: [
				['id' => 'doc-1', 'reviewVerdict' => 'deels_openbaar', 'reviewGrounds' => ['5.1.2.e']],
				['id' => 'doc-2'],
				['id' => 'doc-3', 'reviewVerdict' => 'open', 'reviewGrounds' => []],
			],
			actual: $rows
		);
	}//end testEachDocumentCarriesItsVerdictReadOnceForTheCase()

	/**
	 * Grounds go only on a row that carries a verdict, so they are never shown where the verdict is not.
	 *
	 * @return void
	 */
	public function testGroundsGoOnlyOnRowsThatCarryAVerdict(): void {
		$verdicts = $this->createMock(originalClassName: DocumentVerdicts::class);
		$verdicts->method('byDocument')->willReturn(['doc-1' => 'openbaar', 'doc-3' => 'open']);
		$verdicts->method('groundsByDocument')->willReturn(['doc-1' => [], 'doc-2' => ['5.1.2.e']]);

		$rows = $this->reader(verdicts: $verdicts)->withReviewVerdicts(
			caseId: 'case-1',
			documents: [['id' => 'doc-1'], ['id' => 'doc-2'], ['id' => 'doc-3']]
		);

		self::assertSame(
			expected: [
				['id' => 'doc-1', 'reviewVerdict' => 'openbaar', 'reviewGrounds' => []],
				['id' => 'doc-2'],
				['id' => 'doc-3', 'reviewVerdict' => 'open', 'reviewGrounds' => []],
			],
			actual: $rows
		);
	}//end testGroundsGoOnlyOnRowsThatCarryAVerdict()

	/**
	 * Without the verdicts, or without rows, nothing is read and the rows pass through.
	 *
	 * @return void
	 */
	public function testWithoutVerdictsOrRowsTheRowsAreUnchanged(): void {
		self::assertSame(
			expected: [['id' => 'a']],
			actual: $this->reader(verdicts: null)->withReviewVerdicts(caseId: 'case-1', documents: [['id' => 'a']])
		);

		$verdicts = $this->createMock(originalClassName: DocumentVerdicts::class);
		$verdicts->expects($this->never())->method('byDocument');
		$verdicts->expects($this->never())->method('groundsByDocument');
		self::assertSame(expected: [], actual: $this->reader(verdicts: $verdicts)->withReviewVerdicts(caseId: 'case-1', documents: []));
	}//end testWithoutVerdictsOrRowsTheRowsAreUnchanged()
}//end class
