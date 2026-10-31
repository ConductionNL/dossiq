<?php

/**
 * A package refresh reaches rows the caller may not read.
 *
 * `occ dossiq:package:install --over-local-changes` runs with no user, and a
 * case is a schema with authorization rules: read under the caller's access,
 * the shipped case answered nothing and kept the keys a later package version
 * added (DqSociaalDomein De melding without Grondslag and Gemeld door).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ShippedConfigurationService::refreshRows() on a protected schema.
 *
 * @covers \OCA\Dossiq\Service\Starter\ShippedConfigurationService
 * @covers \OCA\Dossiq\Service\Starter\StarterStore
 *
 * @uses \OCA\Dossiq\Service\Starter\ShippedFingerprint
 * @uses \OCA\Dossiq\Service\Starter\ShippedSets
 */
class PackageRowRefreshAsSystemTest extends TestCase {

	private const CASE_ID = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test.
	 *
	 * @var ShippedConfigurationService
	 */
	private ShippedConfigurationService $shipped;

	/**
	 * A shipped case, stamped at 1.0.0, in a schema the caller may not read.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->shipped = new ShippedConfigurationService(
			store: $this->harness->store,
			sets: new ShippedSets(),
			fingerprint: new ShippedFingerprint(),
			logger: new NullLogger(),
		);

		$case = ['id' => self::CASE_ID, 'title' => 'Melding Jeugdwet gezin Oosthaven', 'assignee' => 'fatma.kaya'];
		$this->harness->seed(schema: 'case', uuid: self::CASE_ID, row: $case);
		$this->shipped->stamp(
			set: 'jeugdwet',
			targetSchema: 'case',
			targetObject: self::CASE_ID,
			object: $case,
			version: '1.0.0',
			shipped: ['title' => 'Melding Jeugdwet gezin Oosthaven', 'assignee' => 'fatma.kaya'],
		);
		$this->harness->register->refusedToCaller = ['case'];
	}//end setUp()

	/**
	 * The row as a newer package version ships it: two keys more.
	 *
	 * @param array<string, mixed> $extra Keys to change in the shipped row.
	 *
	 * @return array<int, array<string, mixed>> The held rows refreshRows() takes.
	 */
	private function held(array $extra = []): array {
		$row = array_merge(
			[
				'legalBasis' => 'Jeugdwet artikel 2.3',
				'assignee' => 'fatma.kaya',
				'initiatorDisplayName' => 'Huisarts, met toestemming van de ouders',
				'title' => 'Melding Jeugdwet gezin Oosthaven',
			],
			$extra
		);

		return [['schemaKey' => 'case_schema', 'schema' => 'case', 'id' => self::CASE_ID, 'row' => $row, 'ownRegister' => false]];
	}//end held()

	/**
	 * Over local changes, the keys the package added land on a row the caller
	 * may not read, whatever order the store keeps its keys in.
	 *
	 * @return void
	 */
	public function testOverLocalChangesFillsTheKeysThePackageAdded(): void {
		$result = $this->shipped->refreshRows(set: 'jeugdwet', version: '1.12.0', rows: $this->held(), overLocalChanges: true);

		$row = $this->harness->register->row(schema: 'case', uuid: self::CASE_ID);
		self::assertSame(expected: [self::CASE_ID], actual: $result['refreshed']);
		self::assertSame(expected: 'Jeugdwet artikel 2.3', actual: $row['legalBasis']);
		self::assertSame(expected: 'Huisarts, met toestemming van de ouders', actual: $row['initiatorDisplayName']);
	}//end testOverLocalChangesFillsTheKeysThePackageAdded()

	/**
	 * An untouched row takes the new keys on a plain install too.
	 *
	 * @return void
	 */
	public function testAPlainInstallRefreshesAnUntouchedRow(): void {
		$result = $this->shipped->refreshRows(set: 'jeugdwet', version: '1.12.0', rows: $this->held(), overLocalChanges: false);

		self::assertSame(expected: [self::CASE_ID], actual: $result['refreshed']);
		self::assertSame(
			expected: 'Jeugdwet artikel 2.3',
			actual: $this->harness->register->row(schema: 'case', uuid: self::CASE_ID)['legalBasis']
		);
	}//end testAPlainInstallRefreshesAnUntouchedRow()

	/**
	 * A plain install keeps a shipped key somebody changed here; only the flag
	 * lets the package's value replace it.
	 *
	 * @return void
	 */
	public function testAPlainInstallKeepsALocalEdit(): void {
		$this->harness->register->rows['case'][self::CASE_ID]['assignee'] = 'pieter';

		$plain = $this->shipped->refreshRows(set: 'jeugdwet', version: '1.12.0', rows: $this->held(), overLocalChanges: false);
		self::assertSame(expected: [self::CASE_ID], actual: $plain['kept']);
		self::assertSame(expected: 'pieter', actual: $this->harness->register->row(schema: 'case', uuid: self::CASE_ID)['assignee']);

		$over = $this->shipped->refreshRows(set: 'jeugdwet', version: '1.12.0', rows: $this->held(), overLocalChanges: true);
		self::assertSame(expected: [self::CASE_ID], actual: $over['refreshed']);
		self::assertSame(expected: 'fatma.kaya', actual: $this->harness->register->row(schema: 'case', uuid: self::CASE_ID)['assignee']);
	}//end testAPlainInstallKeepsALocalEdit()

	/**
	 * The refresh does not open the caller's own reads: a plain read of the
	 * row still goes through the access check.
	 *
	 * @return void
	 */
	public function testTheCallersOwnReadStillChecksAccess(): void {
		self::assertNull(actual: $this->harness->store->row(configKey: 'case_schema', id: self::CASE_ID));
	}//end testTheCallersOwnReadStillChecksAccess()
}//end class
