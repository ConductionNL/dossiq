<?php

/**
 * RecordImportReferences unit tests
 *
 * A number keeps the first case it became, and where a record goes follows
 * from the case it has and the case its number became.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-record-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordImportReferences;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecordImportReferences::rememberNumber() and placement().
 *
 * @covers \OCA\Dossiq\Service\Import\RecordImportReferences
 */
class RecordImportReferencesTest extends TestCase {

	/**
	 * The references, over collaborators these tests never reach.
	 *
	 * @return RecordImportReferences The references.
	 */
	private function references(): RecordImportReferences {
		return new RecordImportReferences(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			store: $this->createMock(originalClassName: RecordImportStore::class),
		);
	}//end references()

	/**
	 * A number keeps the first case it became; a record without a number is not remembered.
	 *
	 * @return void
	 */
	public function testANumberKeepsItsFirstCase(): void {
		$references = $this->references();
		$import = ['dedupeBy' => 'number'];
		$cache = null;

		$references->rememberNumber(import: $import, source: ['number' => ' N-1 '], caseId: 'case-first', cache: $cache);
		$references->rememberNumber(import: $import, source: ['number' => 'N-1'], caseId: 'case-later', cache: $cache);
		$references->rememberNumber(import: $import, source: ['number' => ''], caseId: 'case-none', cache: $cache);

		self::assertSame(expected: ['N-1' => 'case-first'], actual: $cache);
	}//end testANumberKeepsItsFirstCase()

	/**
	 * A record joins its number's case, completes its own, or conflicts when they differ.
	 *
	 * @return void
	 */
	public function testWhereARecordGoes(): void {
		$references = $this->references();

		self::assertSame(expected: ['existing' => '', 'duplicateOf' => ''], actual: $references->placement(existing: '', numbered: ''));
		self::assertSame(expected: ['existing' => '', 'duplicateOf' => 'case-n'], actual: $references->placement(existing: '', numbered: 'case-n'));
		self::assertSame(expected: ['existing' => 'case-own', 'duplicateOf' => ''], actual: $references->placement(existing: 'case-own', numbered: ''));
		self::assertSame(
			expected: ['existing' => 'case-own', 'duplicateOf' => ''],
			actual: $references->placement(existing: 'case-own', numbered: 'case-own')
		);
		self::assertSame(
			expected: ['conflict' => 'The record already has case case-own, but its number became case case-n, so it was not imported.'],
			actual: $references->placement(existing: 'case-own', numbered: 'case-n')
		);
	}//end testWhereARecordGoes()
}//end class
