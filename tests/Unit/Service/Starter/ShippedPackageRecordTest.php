<?php

/**
 * A package's version record, and stamping a row as this instance stores it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedLedgerTargets;
use OCA\Dossiq\Service\Starter\ShippedRowShape;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the package record, the installed version and the stored-row stamp.
 */
#[CoversClass(ShippedConfigurationService::class)]
#[UsesClass(ShippedFingerprint::class)]
#[UsesClass(ShippedLedgerTargets::class)]
#[UsesClass(ShippedRowShape::class)]
#[UsesClass(ShippedSets::class)]
#[UsesClass(StarterStore::class)]
#[UsesClass(JsonEncodedStringProperties::class)]
#[UsesClass(TranslatedText::class)]
final class ShippedPackageRecordTest extends TestCase {

	/**
	 * The ledger's schema slug, as the harness derives it from the config key.
	 */
	private const LEDGER_SCHEMA = 'shippedOrigin';

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
	 * Build the service over a real in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->shipped = new ShippedConfigurationService(
			$this->harness->store,
			new ShippedSets(),
			new ShippedFingerprint(),
			new NullLogger(),
		);
	}//end setUp()

	/**
	 * A recorded package reads as current at the same version and digest.
	 *
	 * @return void
	 */
	public function testARecordedPackageIsCurrentAtItsVersionAndDigest(): void {
		self::assertTrue(condition: $this->shipped->recordPackage(set: 'pkg-woo', version: '1.2.0', digest: 'abc'));

		self::assertTrue(condition: $this->shipped->isPackageCurrent(set: 'pkg-woo', version: '1.2.0', digest: 'abc'));
		self::assertSame(expected: '1.2.0', actual: $this->shipped->installedVersion(set: 'pkg-woo'));
	}//end testARecordedPackageIsCurrentAtItsVersionAndDigest()

	/**
	 * The same version with other files is not current, so it installs again.
	 *
	 * @return void
	 */
	public function testOtherFilesAtTheSameVersionAreNotCurrent(): void {
		$this->shipped->recordPackage(set: 'pkg-woo', version: '1.2.0', digest: 'abc');

		self::assertFalse(condition: $this->shipped->isPackageCurrent(set: 'pkg-woo', version: '1.2.0', digest: 'def'));
	}//end testOtherFilesAtTheSameVersionAreNotCurrent()

	/**
	 * Another version, or a package never recorded, is not current.
	 *
	 * @return void
	 */
	public function testAnotherVersionOrAnUnknownPackageIsNotCurrent(): void {
		$this->shipped->recordPackage(set: 'pkg-woo', version: '1.2.0', digest: 'abc');

		self::assertFalse(condition: $this->shipped->isPackageCurrent(set: 'pkg-woo', version: '1.3.0', digest: 'abc'));
		self::assertFalse(condition: $this->shipped->isPackageCurrent(set: 'pkg-other', version: '1.2.0', digest: 'abc'));
	}//end testAnotherVersionOrAnUnknownPackageIsNotCurrent()

	/**
	 * Recording a package twice keeps one ledger row and moves its version.
	 *
	 * @return void
	 */
	public function testRecordingAgainUpdatesTheOneRecordInPlace(): void {
		$this->shipped->recordPackage(set: 'pkg-woo', version: '1.2.0', digest: 'abc');
		$this->shipped->recordPackage(set: 'pkg-woo', version: '1.3.0', digest: 'xyz');

		$rows = array_values($this->harness->register->all(schema: self::LEDGER_SCHEMA));

		self::assertCount(expectedCount: 1, haystack: $rows);
		self::assertSame(expected: '1.3.0', actual: $rows[0]['setVersion']);
		self::assertSame(expected: 'pkg-woo', actual: $rows[0]['targetObject']);
		self::assertTrue(condition: $this->shipped->isPackageCurrent(set: 'pkg-woo', version: '1.3.0', digest: 'xyz'));
	}//end testRecordingAgainUpdatesTheOneRecordInPlace()

	/**
	 * The installed version is the highest recorded for the set, compared as a version.
	 *
	 * @return void
	 */
	public function testTheInstalledVersionIsTheHighestOfTheSet(): void {
		foreach (['1.9.0' => 'a', '1.10.0' => 'b', '1.2.0' => 'c'] as $version => $object) {
			$this->shipped->stamp(
				set: 'pkg-woo',
				targetSchema: 'caseType',
				targetObject: $object,
				object: ['id' => $object],
				version: $version,
			);
		}

		$this->shipped->stamp(set: 'pkg-other', targetSchema: 'caseType', targetObject: 'z', object: ['id' => 'z'], version: '9.0.0');

		self::assertSame(expected: '1.10.0', actual: $this->shipped->installedVersion(set: 'pkg-woo'));
		self::assertSame(expected: '', actual: $this->shipped->installedVersion(set: 'pkg-never'));
	}//end testTheInstalledVersionIsTheHighestOfTheSet()

	/**
	 * The ledger is available when its schema is configured, and not otherwise.
	 *
	 * @return void
	 */
	public function testTheLedgerIsAvailableOnlyWhenConfigured(): void {
		self::assertTrue(condition: $this->shipped->ledgerAvailable());

		$unconfigured = new StarterStoreHarness(test: $this, unconfigured: [ShippedConfigurationService::LEDGER]);
		$shipped = new ShippedConfigurationService(
			$unconfigured->store,
			new ShippedSets(),
			new ShippedFingerprint(),
			new NullLogger(),
		);

		self::assertFalse(condition: $shipped->ledgerAvailable());
		self::assertFalse(condition: $shipped->recordPackage(set: 'pkg-woo', version: '1.0.0', digest: 'abc'));
	}//end testTheLedgerIsAvailableOnlyWhenConfigured()

	/**
	 * A stamp with no version is refused, logged, and writes nothing.
	 *
	 * @return void
	 */
	public function testAStampThatCannotNameItsSetIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq starter: refusing to stamp an object with no declared set',
			['set' => 'pkg-unknown', 'schema' => 'caseType', 'object' => 'ct-1']
		);
		$shipped = new ShippedConfigurationService(
			$this->harness->store,
			new ShippedSets(),
			new ShippedFingerprint(),
			$logger,
		);

		self::assertFalse(
			condition: $shipped->stamp(set: 'pkg-unknown', targetSchema: 'caseType', targetObject: 'ct-1', object: ['id' => 'ct-1'], version: '')
		);
		self::assertSame(expected: [], actual: $this->harness->register->all(schema: self::LEDGER_SCHEMA));
	}//end testAStampThatCannotNameItsSetIsRefused()

	/**
	 * With an objects key, the stamp hashes the row as stored and the shipped row separately.
	 *
	 * @return void
	 */
	public function testAStampWithAnObjectsKeyHashesTheStoredRow(): void {
		$stored = ['id' => 'ct-1', 'title' => 'Bezwaar', 'filledByStore' => 'yes'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $stored);
		$shippedRow = ['id' => 'ct-1', 'title' => 'Bezwaar'];

		self::assertTrue(
			condition: $this->shipped->stamp(
				set: 'pkg-woo',
				targetSchema: 'caseType',
				targetObject: 'ct-1',
				object: $shippedRow,
				version: '1.0.0',
				objectsKey: 'case_type_schema',
			)
		);

		$row = array_values($this->harness->register->all(schema: self::LEDGER_SCHEMA))[0];
		$fingerprint = new ShippedFingerprint();

		self::assertSame(expected: $fingerprint->hashOf(object: $shippedRow), actual: $row['shippedFingerprint']);
		self::assertNotSame(expected: $row['shippedFingerprint'], actual: $row['fingerprint']);
		self::assertSame(
			expected: ShippedConfigurationService::STATE_SHIPPED,
			actual: $this->shipped->stateOf(targetSchema: 'caseType', targetObject: 'ct-1', object: $stored)['state']
		);
	}//end testAStampWithAnObjectsKeyHashesTheStoredRow()

	/**
	 * With an objects key but no stored row, the given object is stamped for both hashes.
	 *
	 * @return void
	 */
	public function testAStampWithAnObjectsKeyButNoStoredRowHashesTheGivenObject(): void {
		$object = ['id' => 'ct-9', 'title' => 'Melding'];

		$this->shipped->stamp(
			set: 'pkg-woo',
			targetSchema: 'caseType',
			targetObject: 'ct-9',
			object: $object,
			version: '1.0.0',
			objectsKey: 'case_type_schema',
		);

		$row = array_values($this->harness->register->all(schema: self::LEDGER_SCHEMA))[0];

		self::assertSame(expected: $row['fingerprint'], actual: $row['shippedFingerprint']);
		self::assertSame(expected: (new ShippedFingerprint())->hashOf(object: $object), actual: $row['fingerprint']);
	}//end testAStampWithAnObjectsKeyButNoStoredRowHashesTheGivenObject()
}//end class
