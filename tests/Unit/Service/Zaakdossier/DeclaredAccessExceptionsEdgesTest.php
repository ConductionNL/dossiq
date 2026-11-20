<?php

/**
 * DeclaredAccessExceptions: readers out of step with a recorded exception, an
 * exception without a set date, and a history that is not a list.
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
 * @spec openspec/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;
use OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions
 * @uses   \OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue
 */
class DeclaredAccessExceptionsEdgesTest extends TestCase {

	/**
	 * The readers double.
	 *
	 * @var RestrictedDocumentReaders&MockObject
	 */
	private RestrictedDocumentReaders&MockObject $readers;

	/**
	 * The file restriction double.
	 *
	 * @var FileRestrictionMirror&MockObject
	 */
	private FileRestrictionMirror&MockObject $files;

	/**
	 * The records saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Readers that derive the listed people and groups, and record what is saved.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved   = [];
		$this->readers = $this->createMock(originalClassName: RestrictedDocumentReaders::class);
		$this->readers->method('withReaders')->willReturnCallback(
			static function (array $record): array {
				$exception = (array)($record[AccessExceptionValue::PROPERTY] ?? []);
				return [
					...$record,
					'restricted' => true,
					'accessUsers' => (array)($exception['users'] ?? []),
					'accessGroups' => (array)($exception['groups'] ?? []),
					'accessHandlers' => [],
				];
			}
		);
		$this->readers->method('save')->willReturnCallback(
			function (array $record): string {
				$this->saved[] = $record;
				return (string)$record['id'];
			}
		);
		$this->files = $this->createMock(originalClassName: FileRestrictionMirror::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return DeclaredAccessExceptions The service.
	 */
	private function service(): DeclaredAccessExceptions {
		return new DeclaredAccessExceptions(readers: $this->readers, files: $this->files);
	}//end service()

	/**
	 * An exception its history already holds, on a record whose readers lag, is saved again
	 * without a new history entry and without touching the file.
	 *
	 * @return void
	 */
	public function testARecordedExceptionWithStaleReadersIsSavedAgain(): void {
		$exception = ['users' => ['pieter'], 'groups' => [], 'reason' => 'prijzen', 'setBy' => 'pieter', 'setAt' => 'then'];
		$history   = [['action' => 'set', 'new' => $exception]];
		$record    = [
			'id' => 'doc',
			AccessExceptionValue::PROPERTY => $exception,
			AccessExceptionValue::HISTORY => $history,
			'restricted' => true,
			'accessUsers' => ['someone-else'],
			'accessGroups' => [],
			'accessHandlers' => [],
		];
		$this->files->expects(self::never())->method('restrict');

		self::assertTrue(condition: $this->service()->apply(record: $record, before: $record));
		self::assertCount(expectedCount: 1, haystack: $this->saved);
		self::assertSame(expected: $history, actual: $this->saved[0][AccessExceptionValue::HISTORY]);

		// Once the readers match, the same record is left alone.
		$this->saved = [];
		$record['accessUsers'] = ['pieter'];
		self::assertFalse(condition: $this->service()->apply(record: $record, before: $record));
		self::assertSame(expected: [], actual: $this->saved);
	}//end testARecordedExceptionWithStaleReadersIsSavedAgain()

	/**
	 * An exception without a set date is recorded at the time of applying; a history
	 * that is not a list is replaced by the one new entry.
	 *
	 * @return void
	 */
	public function testAnUndatedExceptionIsRecordedNowOverAHistoryThatIsNotAList(): void {
		$record = [
			'id' => 'doc',
			AccessExceptionValue::PROPERTY => ['users' => ['pieter'], 'groups' => ['g'], 'reason' => 'prijzen'],
			AccessExceptionValue::HISTORY => 'not a list',
		];

		$before = time();
		self::assertTrue(condition: $this->service()->apply(record: $record, before: null));
		$after = time();

		$history = $this->saved[0][AccessExceptionValue::HISTORY];
		self::assertCount(expectedCount: 1, haystack: $history);
		self::assertSame(expected: 'set', actual: $history[0]['action']);
		self::assertSame(expected: '', actual: $history[0]['by']);
		self::assertNull(actual: $history[0]['old']);
		self::assertSame(expected: 'prijzen', actual: $history[0]['reason']);
		$at = strtotime($history[0]['at']);
		self::assertGreaterThanOrEqual(expected: $before, actual: $at);
		self::assertLessThanOrEqual(expected: $after, actual: $at);
	}//end testAnUndatedExceptionIsRecordedNowOverAHistoryThatIsNotAList()
}//end class
