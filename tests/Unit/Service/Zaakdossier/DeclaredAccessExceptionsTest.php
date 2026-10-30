<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions
 *
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 */
class DeclaredAccessExceptionsTest extends TestCase {

	/** @var RestrictedDocumentReaders&MockObject The readers double. */
	private RestrictedDocumentReaders&MockObject $readers;

	/** @var FileRestrictionMirror&MockObject The file restriction double. */
	private FileRestrictionMirror&MockObject $files;

	/** @var list<array<string, mixed>> The records saved. */
	private array $saved = [];

	/**
	 * Readers that derive as the real ones do for a document on no case, and record what is saved.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->readers = $this->createMock(originalClassName: RestrictedDocumentReaders::class);
		$derive = static function (array $record): array {
			$exception = (array)($record['accessException'] ?? []);
			return [
				...$record,
				'restricted' => true,
				'accessUsers' => (array)($exception['users'] ?? []),
				'accessGroups' => (array)($exception['groups'] ?? []),
				'accessHandlers' => [],
			];
		};
		$this->readers->method('withReaders')->willReturnCallback($derive);
		$this->readers->method('save')->willReturnCallback(
			function (array $record) use ($derive): string {
				$this->saved[] = $derive($record);
				return (string)$record['id'];
			}
		);
		$this->files = $this->createMock(originalClassName: FileRestrictionMirror::class);
	}//end setUp()

	/**
	 * The exception the example set declares on Offerte herplant essen.
	 *
	 * @return array<string, mixed>
	 */
	private function declared(): array {
		return [
			'users' => ['pieter'],
			'groups' => ['zuiddrecht-vergunningen'],
			'reason' => 'Bedrijfsgevoelige prijzen',
			'setBy' => 'pieter',
			'setAt' => '2026-10-20T09:45:00+00:00',
		];
	}//end declared()

	/**
	 * @return DeclaredAccessExceptions
	 */
	private function service(): DeclaredAccessExceptions {
		return new DeclaredAccessExceptions(readers: $this->readers, files: $this->files);
	}//end service()

	/**
	 * @return void
	 */
	public function testADeclaredExceptionGetsItsHistoryReadersAndFile(): void {
		$this->files->expects($this->once())->method('restrict')->with(751, ['pieter'], ['zuiddrecht-vergunningen'], 'Bedrijfsgevoelige prijzen');

		$applied = $this->service()->apply(record: ['id' => 'doc-1', 'fileId' => 751, 'accessException' => $this->declared()], before: null);

		self::assertTrue(condition: $applied);
		self::assertCount(expectedCount: 1, haystack: $this->saved);
		$history = $this->saved[0]['accessExceptionHistory'];
		self::assertCount(expectedCount: 1, haystack: $history);
		self::assertSame(expected: 'set', actual: $history[0]['action']);
		self::assertSame(expected: 'pieter', actual: $history[0]['by']);
		self::assertSame(expected: '2026-10-20T09:45:00+00:00', actual: $history[0]['at']);
		self::assertNull(actual: $history[0]['old']);
		self::assertSame(expected: 'Bedrijfsgevoelige prijzen', actual: $history[0]['reason']);
	}//end testADeclaredExceptionGetsItsHistoryReadersAndFile()

	/**
	 * Written before its file: the history and readers now, the file once the record names it.
	 *
	 * @return void
	 */
	public function testTheFileIsRestrictedOnceTheRecordNamesIt(): void {
		$this->files->expects($this->once())->method('restrict')->with(751);
		$first = ['id' => 'doc-1', 'accessException' => $this->declared()];
		self::assertTrue(condition: $this->service()->apply(record: $first, before: null));

		$inStep = [...$this->saved[0]];
		self::assertTrue(condition: $this->service()->apply(record: [...$inStep, 'fileId' => 751], before: $inStep));
		self::assertFalse(condition: $this->service()->apply(record: [...$inStep, 'fileId' => 751], before: [...$inStep, 'fileId' => 751]));
	}//end testTheFileIsRestrictedOnceTheRecordNamesIt()

	/**
	 * What the writer stored is in step and is left alone.
	 *
	 * @return void
	 */
	public function testAnExceptionTheWriterStoredIsLeftAlone(): void {
		$this->files->expects($this->never())->method('restrict');
		$exception = $this->declared();
		$record = [
			'id' => 'doc-1',
			'fileId' => 751,
			'accessException' => $exception,
			'accessExceptionHistory' => [
				['action' => 'set', 'by' => 'pieter', 'at' => 'x', 'old' => null, 'new' => $exception, 'reason' => 'Bedrijfsgevoelige prijzen'],
			],
			'restricted' => true,
			'accessUsers' => ['pieter'],
			'accessGroups' => ['zuiddrecht-vergunningen'],
			'accessHandlers' => [],
		];

		self::assertFalse(condition: $this->service()->apply(record: $record, before: $record));
		self::assertSame(expected: [], actual: $this->saved);
	}//end testAnExceptionTheWriterStoredIsLeftAlone()

	/**
	 * A declared change of an exception the history already holds is recorded as a change.
	 *
	 * @return void
	 */
	public function testAChangedDeclarationIsRecordedAsAChange(): void {
		$old = ['users' => ['pieter'], 'groups' => [], 'reason' => 'Eerder', 'setBy' => 'pieter', 'setAt' => 'x'];
		$record = [
			'id' => 'doc-1',
			'accessException' => $this->declared(),
			'accessExceptionHistory' => [['action' => 'set', 'by' => 'pieter', 'at' => 'x', 'old' => null, 'new' => $old, 'reason' => 'Eerder']],
		];

		self::assertTrue(condition: $this->service()->apply(record: $record, before: $record));
		$history = $this->saved[0]['accessExceptionHistory'];
		self::assertSame(expected: 'change', actual: $history[1]['action']);
		self::assertSame(expected: $old, actual: $history[1]['old']);
	}//end testAChangedDeclarationIsRecordedAsAChange()

	/**
	 * A document without an exception is not touched.
	 *
	 * @return void
	 */
	public function testADocumentWithoutAnExceptionIsNotTouched(): void {
		$this->files->expects($this->never())->method('restrict');

		self::assertFalse(condition: $this->service()->apply(record: ['id' => 'doc-1', 'fileId' => 751], before: null));
		self::assertSame(expected: [], actual: $this->saved);
	}//end testADocumentWithoutAnExceptionIsNotTouched()

	/**
	 * When the file cannot be restricted, nothing is saved, so the next write tries again.
	 *
	 * @return void
	 */
	public function testNothingIsSavedWhenTheFileCannotBeRestricted(): void {
		$this->files->method('restrict')->willThrowException(new \RuntimeException('no'));

		$this->expectException(exception: \RuntimeException::class);
		try {
			$this->service()->apply(record: ['id' => 'doc-1', 'fileId' => 751, 'accessException' => $this->declared()], before: null);
		} finally {
			self::assertSame(expected: [], actual: $this->saved);
		}
	}//end testNothingIsSavedWhenTheFileCannotBeRestricted()
}//end class
