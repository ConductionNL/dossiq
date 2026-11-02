<?php

/**
 * Golden test for adopting and refreshing shipped rows in ShippedConfigurationService.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What adopt(), adoptOverLocalChange() and refreshRows() answer, write and log, per case.
 *
 * @covers \OCA\Dossiq\Service\Starter\ShippedConfigurationService
 * @covers \OCA\Dossiq\Service\Starter\ShippedRowAdopter
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 * @uses \OCA\Dossiq\Service\Starter\ShippedSets
 * @uses \OCA\Dossiq\Service\Starter\ShippedFingerprint
 * @uses \OCA\Dossiq\Service\Starter\ShippedLedgerTargets
 * @uses \OCA\Dossiq\Service\Starter\ShippedRowShape
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Support\JsonEncodedStringProperties
 *
 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
 */
class ShippedRowAdoptionGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const SET = ShippedSets::MUNICIPAL_ROLES;

	/**
	 * The store over an in-memory register.
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
	 * The warnings logged, with their context.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Seed six case types: untouched, changed here, never stamped, stamped by another set, same as shipped, and one to fail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = ['message' => $message, 'context' => $context];
			}
		);
		$this->shipped = new ShippedConfigurationService(
			store: $this->harness->store,
			sets: new ShippedSets(),
			fingerprint: new ShippedFingerprint(),
			logger: $logger,
		);

		foreach (['untouched', 'changed', 'local', 'foreign', 'same'] as $id) {
			$this->harness->seed(schema: 'caseType', uuid: $id, row: ['title' => 'Type ' . $id, 'processingDeadline' => 'P6W', 'note' => 'kept']);
		}

		foreach (['untouched', 'changed', 'same'] as $id) {
			$this->shipped->stamp(set: self::SET, targetSchema: 'caseType', targetObject: $id, object: ['id' => $id, 'title' => 'Type ' . $id, 'processingDeadline' => 'P6W', 'note' => 'kept'], version: '1.0.0');
		}

		$this->shipped->stamp(set: 'another-set', targetSchema: 'caseType', targetObject: 'foreign', object: ['id' => 'foreign', 'title' => 'Type foreign', 'processingDeadline' => 'P6W', 'note' => 'kept'], version: '2.0.0');
		$this->harness->seed(schema: 'caseType', uuid: 'changed', row: ['title' => 'Type changed', 'processingDeadline' => 'P8W', 'note' => 'kept']);
	}//end setUp()

	/**
	 * The adoptions: which act, which row, and whether the write fails.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null, 3: bool}>
	 */
	public static function adoptions(): array {
		return [
			'adopt an untouched row' => ['adopt', 'untouched', null, false],
			'adopt an untouched row at a version' => ['adopt', 'untouched', '3.1.0', false],
			'adopt a row changed here' => ['adopt', 'changed', null, false],
			'adopt a row nobody stamped' => ['adopt', 'local', null, false],
			'adopt a missing row' => ['adopt', 'gone', null, false],
			'adopt when the write fails' => ['adopt', 'untouched', null, true],
			'take over a row changed here' => ['over', 'changed', null, false],
			'take over a row nobody stamped' => ['over', 'local', null, false],
			'take over a missing row' => ['over', 'gone', null, false],
		];
	}//end adoptions()

	/**
	 * The answer, the rows and the warnings match the recording.
	 *
	 * @param string      $act     `adopt` or `over`.
	 * @param string      $id      The row.
	 * @param string|null $version The version adopt() is given.
	 * @param bool        $fails   Whether the case type write fails.
	 *
	 * @dataProvider adoptions
	 *
	 * @return void
	 */
	public function testAnAdoptionMatchesTheRecording(string $act, string $id, ?string $version, bool $fails): void {
		if ($fails === true) {
			$this->harness->register->refusedSchemas = ['caseType'];
		}

		$shipped = ['title' => 'Type ' . $id, 'processingDeadline' => 'P12W'];
		if ($act === 'adopt') {
			$answer = $this->shipped->adopt(
				targetSchema: 'caseType',
				objectsKey: 'case_type_schema',
				targetObject: $id,
				set: self::SET,
				newShipped: $shipped,
				version: $version,
			);
		} else {
			$answer = $this->shipped->adoptOverLocalChange(
				targetSchema: 'caseType',
				objectsKey: 'case_type_schema',
				targetObject: $id,
				set: self::SET,
				newShipped: $shipped,
			);
		}

		$this->assertMatchesGolden(name: $this->goldenName(), value: $this->outcome(answer: $answer));
	}//end testAnAdoptionMatchesTheRecording()

	/**
	 * Refreshing every kind of row, with and without taking over local changes.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function refreshes(): array {
		return [
			'refresh, keeping local changes' => [false],
			'refresh over local changes' => [true],
		];
	}//end refreshes()

	/**
	 * What refreshRows() answers, writes and logs matches the recording.
	 *
	 * @param bool $overLocalChanges Whether a row changed here takes the set's keys too.
	 *
	 * @dataProvider refreshes
	 *
	 * @return void
	 */
	public function testARefreshMatchesTheRecording(bool $overLocalChanges): void {
		$rows = [];
		foreach (['untouched', 'changed', 'local', 'foreign', 'same', 'gone', 'elsewhere'] as $id) {
			$row = ['title' => 'Type ' . $id, 'processingDeadline' => 'P12W'];
			if ($id === 'same') {
				$row = ['title' => 'Type same', 'processingDeadline' => 'P6W', 'note' => 'kept', 'id' => 'same'];
			}

			$rows[] = ['schemaKey' => 'case_type_schema', 'schema' => 'caseType', 'id' => $id, 'row' => $row, 'ownRegister' => ($id === 'elsewhere')];
		}

		$answer = $this->shipped->refreshRows(set: self::SET, version: '4.0.0', rows: $rows, overLocalChanges: $overLocalChanges);

		$this->assertMatchesGolden(name: $this->goldenName(), value: $this->outcome(answer: $answer));
	}//end testARefreshMatchesTheRecording()

	/**
	 * The answer, the stored rows (stamp times masked) and the warnings.
	 *
	 * @param array<string, mixed> $answer The answer.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(array $answer): array {
		$ledger = [];
		foreach ($this->harness->register->all(schema: 'shippedOrigin') as $row) {
			$row['seededAt'] = (($row['seededAt'] ?? '') === '') ? '' : '<stamped>';
			$ledger[] = $row;
		}

		return [
			'answer' => $answer,
			'caseTypes' => $this->harness->register->all(schema: 'caseType'),
			'ledger' => $ledger,
			'warnings' => $this->warnings,
		];
	}//end outcome()

	/**
	 * The golden file of the running case.
	 *
	 * @return string
	 */
	private function goldenName(): string {
		return 'shipped-row-adoption/' . trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $this->dataName())), '-');
	}//end goldenName()
}//end class
