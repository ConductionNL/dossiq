<?php

/**
 * What shipped, what was changed here, and what is ours.
 *
 * Covers the provenance ledger end to end over a real in-memory register: the
 * stamp, the three states, the offer of a newer version, and the refusal to
 * overwrite a local change without being told the loss is accepted.
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
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
 * Unit tests for ShippedConfigurationService and its fingerprint.
 *
 * @covers \OCA\Dossiq\Service\Starter\ShippedConfigurationService
 * @covers \OCA\Dossiq\Service\Starter\ShippedFingerprint
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 * @uses \OCA\Dossiq\Service\Starter\ShippedSets
 */
class SeedDataOriginTest extends TestCase {

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
	 * Build the service over a real store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->shipped = new ShippedConfigurationService($this->harness->store, new NullLogger());
	}//end setUp()

	/**
	 * A seeded case type nobody edited reads as shipped and untouched, and
	 * names the set and version it came from.
	 *
	 * @return void
	 */
	public function testASeededObjectNobodyEditedReadsAsShipped(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar', 'processingDeadline' => 'P6W'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);

		self::assertTrue(
			condition: $this->shipped->stamp(
				set: ShippedSets::BEZWAAR_BEROEP,
				targetSchema: 'caseType',
				targetObject: 'ct-1',
				object: $caseType,
			)
		);

		$state = $this->shipped->stateOf(targetSchema: 'caseType', targetObject: 'ct-1', object: $caseType);

		self::assertSame(expected: ShippedConfigurationService::STATE_SHIPPED, actual: $state['state']);
		self::assertSame(expected: ShippedSets::BEZWAAR_BEROEP, actual: $state['set']);
		self::assertSame(expected: '1.0.0', actual: $state['setVersion']);
	}//end testASeededObjectNobodyEditedReadsAsShipped()

	/**
	 * An administrator who edited a shipped object is told so, and told what
	 * it came from.
	 *
	 * @return void
	 */
	public function testAnEditedShippedObjectReadsAsChangedHere(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		$edited = ($caseType + []);
		$edited['title'] = 'Bezwaarschrift';

		$state = $this->shipped->stateOf(targetSchema: 'caseType', targetObject: 'ct-1', object: $edited);

		self::assertSame(expected: ShippedConfigurationService::STATE_CHANGED, actual: $state['state']);
		self::assertSame(expected: ShippedSets::BEZWAAR_BEROEP, actual: $state['set']);
	}//end testAnEditedShippedObjectReadsAsChangedHere()

	/**
	 * An object nobody shipped is nobody's but theirs.
	 *
	 * @return void
	 */
	public function testAnUnseededObjectReadsAsLocallyAuthored(): void {
		$state = $this->shipped->stateOf(
			targetSchema: 'caseType',
			targetObject: 'ct-9',
			object: ['id' => 'ct-9', 'title' => 'Eigen zaaktype'],
		);

		self::assertSame(expected: ShippedConfigurationService::STATE_LOCAL, actual: $state['state']);
		self::assertSame(expected: '', actual: $state['set']);
	}//end testAnUnseededObjectReadsAsLocallyAuthored()

	/**
	 * A re-run seed rewrites its ledger row rather than adding a second one.
	 *
	 * Without this a repair step that runs on every upgrade lists everything
	 * twice, and the screen stops being read.
	 *
	 * @return void
	 */
	public function testStampingTwiceKeepsOneLedgerRow(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);

		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		self::assertCount(expectedCount: 1, haystack: $this->harness->register->all(schema: 'shippedOrigin'));
	}//end testStampingTwiceKeepsOneLedgerRow()

	/**
	 * A set with no declared version is not stamped at all.
	 *
	 * ADR-102: a provenance row that cannot name its set would report the
	 * object as shipped and be unable to say from what.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSetIsNotStamped(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1']);

		self::assertFalse(
			condition: $this->shipped->stamp(
				set: 'a-set-nobody-declared',
				targetSchema: 'caseType',
				targetObject: 'ct-1',
				object: ['id' => 'ct-1'],
			)
		);
		self::assertSame(expected: [], actual: $this->harness->register->all(schema: 'shippedOrigin'));
	}//end testAnUndeclaredSetIsNotStamped()

	/**
	 * An untouched shipped object takes the newer version.
	 *
	 * @return void
	 */
	public function testAnUntouchedObjectTakesTheNewerVersion(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar', 'processingDeadline' => 'P6W'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		$result = $this->shipped->adopt(
			targetSchema: 'caseType',
			objectsKey: 'case_type_schema',
			targetObject: 'ct-1',
			set: ShippedSets::BEZWAAR_BEROEP,
			newShipped: ['title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
		);

		self::assertTrue(condition: $result['adopted']);
		self::assertSame(
			expected: 'P12W',
			actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1')['processingDeadline']
		);
	}//end testAnUntouchedObjectTakesTheNewerVersion()

	/**
	 * An upgrade does not overwrite a local change, and says why.
	 *
	 * 🔑 THE FAILURE THIS PINS IS UNRECOVERABLE. An adoption that silently
	 * overwrote an edited case type would destroy configuration somebody made
	 * on purpose, with no undo anywhere.
	 *
	 * @return void
	 */
	public function testAnUpgradeDoesNotOverwriteALocalChange(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar', 'processingDeadline' => 'P6W'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		$edited = ($caseType + []);
		$edited['processingDeadline'] = 'P8W';
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $edited);

		$refused = $this->shipped->adopt(
			targetSchema: 'caseType',
			objectsKey: 'case_type_schema',
			targetObject: 'ct-1',
			set: ShippedSets::BEZWAAR_BEROEP,
			newShipped: ['title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
		);

		self::assertFalse(condition: $refused['adopted']);
		self::assertSame(expected: 'changed_locally', actual: $refused['reason']);
		self::assertSame(
			expected: 'P8W',
			actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1')['processingDeadline']
		);

		$accepted = $this->shipped->adopt(
			targetSchema: 'caseType',
			objectsKey: 'case_type_schema',
			targetObject: 'ct-1',
			set: ShippedSets::BEZWAAR_BEROEP,
			newShipped: ['title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
			accepted: true,
		);

		self::assertTrue(condition: $accepted['adopted']);
		self::assertSame(
			expected: 'P12W',
			actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1')['processingDeadline']
		);
	}//end testAnUpgradeDoesNotOverwriteALocalChange()

	/**
	 * The overview names the set, the state and whether an update is waiting.
	 *
	 * @return void
	 */
	public function testTheOverviewNamesTheStateAndTheWaitingUpdate(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		// An older seeded version, as an instance that has not upgraded yet
		// would carry it.
		$ledger = $this->harness->register->all(schema: 'shippedOrigin')[0];
		$ledger['setVersion'] = '0.9.0';
		$this->harness->seed(schema: 'shippedOrigin', uuid: (string)$ledger['id'], row: $ledger);

		$overview = $this->shipped->overview(targetSchema: 'caseType', objectsKey: 'case_type_schema');

		self::assertNotNull(actual: $overview);
		self::assertCount(expectedCount: 1, haystack: $overview);
		self::assertSame(expected: 'Bezwaar', actual: $overview[0]['title']);
		self::assertSame(expected: '0.9.0', actual: $overview[0]['setVersion']);
		self::assertSame(expected: '1.0.0', actual: $overview[0]['latestVersion']);
		self::assertTrue(condition: $overview[0]['updateAvailable']);
	}//end testTheOverviewNamesTheStateAndTheWaitingUpdate()

	/**
	 * A seeded row somebody deleted here is reported as removed, not dropped.
	 *
	 * @return void
	 */
	public function testASeededRowDeletedHereIsReportedAsRemoved(): void {
		$caseType = ['id' => 'ct-1', 'title' => 'Bezwaar'];
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: $caseType);
		$this->shipped->stamp(
			set: ShippedSets::BEZWAAR_BEROEP,
			targetSchema: 'caseType',
			targetObject: 'ct-1',
			object: $caseType,
		);

		unset($this->harness->register->rows['caseType']['ct-1']);

		$overview = $this->shipped->overview(targetSchema: 'caseType', objectsKey: 'case_type_schema');

		self::assertNotNull(actual: $overview);
		self::assertSame(expected: 'removed', actual: $overview[0]['state']);
		self::assertFalse(condition: $overview[0]['updateAvailable']);
	}//end testASeededRowDeletedHereIsReportedAsRemoved()

	/**
	 * The fingerprint ignores what the platform writes and notices what a
	 * person types.
	 *
	 * Without the ignore list every shipped object reads as changed the moment
	 * anything re-saves it, which is the same as having no answer at all.
	 *
	 * @return void
	 */
	public function testTheFingerprintIgnoresPlatformFieldsAndNoticesEdits(): void {
		$shipped = ['title' => 'Bezwaar', 'processingDeadline' => 'P6W'];
		$stored = [
			'@self' => ['register' => 'dossiq'],
			'id' => 'ct-1',
			'version' => 4,
			'updated' => '2026-09-15T08:00:00Z',
			'processingDeadline' => 'P6W',
			'title' => 'Bezwaar',
		];

		self::assertTrue(condition: ShippedFingerprint::matches(object: $stored, fingerprint: ShippedFingerprint::of(object: $shipped)));

		$stored['processingDeadline'] = 'P8W';

		self::assertFalse(condition: ShippedFingerprint::matches(object: $stored, fingerprint: ShippedFingerprint::of(object: $shipped)));
	}//end testTheFingerprintIgnoresPlatformFieldsAndNoticesEdits()

	/**
	 * An empty fingerprint never matches.
	 *
	 * A stamp that failed leaves no hash, and "no hash" must not read as
	 * "identical to nothing".
	 *
	 * @return void
	 */
	public function testAnEmptyFingerprintNeverMatches(): void {
		self::assertFalse(condition: ShippedFingerprint::matches(object: [], fingerprint: ''));
	}//end testAnEmptyFingerprintNeverMatches()
}//end class
