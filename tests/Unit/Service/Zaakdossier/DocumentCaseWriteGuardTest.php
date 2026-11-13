<?php

/**
 * A write to a document needs the change right on every case it belongs to.
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
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard
 */
class DocumentCaseWriteGuardTest extends TestCase {

	/**
	 * The guard where the caller may change case-a and case-b but not case-x.
	 *
	 * @return DocumentCaseWriteGuard
	 */
	private function guard(): DocumentCaseWriteGuard {
		$store = $this->createMock(originalClassName: DocumentCaseIds::class);
		$store->method('caseIdsOf')->willReturnCallback(
			callback: static fn (string $recordId): array => [
				'on-two' => ['case-a', 'case-b'],
				'also-on-x' => ['case-a', 'case-x'],
				'on-none' => [],
			][$recordId] ?? []
		);
		$uploads = $this->createMock(originalClassName: DossierUploadHandler::class);
		$uploads->method('hasCaseUploadAccess')->willReturnCallback(
			callback: static fn (IUser $user, string $caseId): bool => $caseId !== 'case-x'
		);

		return new DocumentCaseWriteGuard(caseIds: $store, uploads: $uploads);
	}//end guard()

	/**
	 * The first case the caller may not change is named; every case allowed passes.
	 *
	 * @return void
	 */
	public function testNamesTheCaseTheCallerMayNotChange(): void {
		$user = $this->createMock(originalClassName: IUser::class);

		self::assertNull(actual: $this->guard()->refusedCase(user: $user, documentId: 'on-two'));
		self::assertSame(expected: 'case-x', actual: $this->guard()->refusedCase(user: $user, documentId: 'also-on-x'));
		self::assertNull(actual: $this->guard()->refusedCase(user: $user, documentId: 'on-none'));
	}//end testNamesTheCaseTheCallerMayNotChange()

	/**
	 * Over many documents, the first one refused is named with its case.
	 *
	 * @return void
	 */
	public function testNamesTheFirstRefusedDocumentOfABatch(): void {
		$user = $this->createMock(originalClassName: IUser::class);

		self::assertSame(
			expected: ['documentId' => 'also-on-x', 'caseId' => 'case-x'],
			actual: $this->guard()->firstRefused(user: $user, documentIds: ['on-two', 'also-on-x', 'on-none'])
		);
		self::assertNull(actual: $this->guard()->firstRefused(user: $user, documentIds: ['on-two', 'on-none']));
	}//end testNamesTheFirstRefusedDocumentOfABatch()

	/**
	 * Each row carries whether the caller may change every case of its document; a row without an id may not.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesTheChangeRight(): void {
		$user = $this->createMock(originalClassName: IUser::class);

		self::assertSame(
			expected: [
				['id' => 'on-two', 'titel' => 'A', 'canChangeAccess' => true],
				['id' => 'also-on-x', 'canChangeAccess' => false],
				['id' => 'on-none', 'canChangeAccess' => true],
				['titel' => 'no id', 'canChangeAccess' => false],
			],
			actual: $this->guard()->withChangeRight(
				user: $user,
				documents: [['id' => 'on-two', 'titel' => 'A'], ['id' => 'also-on-x'], ['id' => 'on-none'], ['titel' => 'no id']]
			)
		);
	}//end testEveryRowCarriesTheChangeRight()

	/**
	 * A case shared by many rows is asked about once.
	 *
	 * @return void
	 */
	public function testACaseIsCheckedOncePerListing(): void {
		$store = $this->createMock(originalClassName: DocumentCaseIds::class);
		$store->method('caseIdsOf')->willReturn(['case-a']);
		$uploads = $this->createMock(originalClassName: DossierUploadHandler::class);
		$uploads->expects(self::once())->method('hasCaseUploadAccess')->with(self::anything(), 'case-a')->willReturn(true);
		$guard = new DocumentCaseWriteGuard(caseIds: $store, uploads: $uploads);

		$rows = $guard->withChangeRight(user: $this->createMock(originalClassName: IUser::class), documents: [['id' => 'd1'], ['id' => 'd2']]);

		self::assertSame(expected: [true, true], actual: array_column($rows, 'canChangeAccess'));
	}//end testACaseIsCheckedOncePerListing()
}//end class
