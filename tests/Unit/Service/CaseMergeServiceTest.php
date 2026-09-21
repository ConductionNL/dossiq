<?php

/**
 * Dossiq CaseMergeService test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseMergeService;
use OCA\Dossiq\Service\Cases\CaseMergeRelink;
use OCA\Dossiq\Service\Cases\CaseMergeRule;
use OCA\Dossiq\Service\Cases\CaseMergeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The dossiq half of a merge: the rows move, the old case points at the
 * survivor, and the clock the applicant is owed runs on exactly one of them.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergeServiceTest extends TestCase {
	/**
	 * The object store every test writes through.
	 *
	 * @var FakeMergeObjectService
	 */
	private FakeMergeObjectService $objects;

	/**
	 * The term writer, as a double of the real class.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * Build the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new FakeMergeObjectService();
		$this->terms = $this->createMock(TermijnService::class);
	}//end setUp()

	/**
	 * The merged case says where it went and how it ended.
	 *
	 * @return void
	 */
	public function testTheMergedCasePointsAtTheSurvivor(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a', 'title' => 'First']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b', 'title' => 'Second']);

		$this->service()->applyMerge(mergedId: 'case-b', survivorId: 'case-a');

		$this->assertSame('case-a', $this->objects->read('2', 'case-b')['mergedInto']);
		$this->assertSame('merged', $this->objects->read('2', 'case-b')['endingAct']);
		$this->assertArrayNotHasKey('mergedInto', $this->objects->read('2', 'case-a'));
	}//end testTheMergedCasePointsAtTheSurvivor()

	/**
	 * Scenario: The merged term is closed, the survivor's counts.
	 *
	 * @return void
	 */
	public function testTheMergedTermIsCompletedAndTheSurvivorsIsUntouched(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b']);

		$this->terms->method('getTermijnInstanceForZaak')->willReturnCallback(
			static fn (string $caseId): ?array => ($caseId === 'case-b'
				? ['id' => 'term-b', 'status' => 'lopend']
				: ['id' => 'term-a', 'status' => 'lopend'])
		);

		$completed = [];
		$this->terms->method('markTermijnCompleted')->willReturnCallback(
			static function (string $termInstanceId, $voltooiDatum = null, string $documentLink = '', string $rationale = '') use (&$completed): ?array {
				$completed[] = ['id' => $termInstanceId, 'rationale' => $rationale];
				return ['id' => $termInstanceId];
			}
		);

		$this->service()->applyMerge(mergedId: 'case-b', survivorId: 'case-a');

		$this->assertCount(1, $completed);
		$this->assertSame('term-b', $completed[0]['id']);
		$this->assertStringContainsString('samengevoegd', $completed[0]['rationale']);
	}//end testTheMergedTermIsCompletedAndTheSurvivorsIsUntouched()

	/**
	 * A term that already ended is not ended again.
	 *
	 * @return void
	 */
	public function testAClosedTermIsLeftAlone(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b']);

		$this->terms->method('getTermijnInstanceForZaak')
			->willReturn(['id' => 'term-b', 'status' => 'completed']);
		$this->terms->expects($this->never())->method('markTermijnCompleted');

		$this->service()->applyMerge(mergedId: 'case-b', survivorId: 'case-a');
	}//end testAClosedTermIsLeftAlone()

	/**
	 * Scenario: A duplicate is merged. The rows the schema declares move to
	 * the survivor, and the move is written down.
	 *
	 * @return void
	 */
	public function testDeclaredRowsMoveToTheSurvivorAndTheMoveIsRecorded(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b']);
		$this->objects->store('3', 'role-1', ['id' => 'role-1', 'case' => 'case-b']);
		$this->objects->store('3', 'role-2', ['id' => 'role-2', 'case' => 'case-a']);

		$this->service()->applyMerge(mergedId: 'case-b', survivorId: 'case-a');

		$this->assertSame('case-a', $this->objects->read('3', 'role-1')['case']);
		$this->assertSame('case-a', $this->objects->read('3', 'role-2')['case']);
		$this->assertSame(
			[['schema' => 'role', 'id' => 'role-1']],
			$this->objects->read('2', 'case-b')['mergeRelinked']
		);
	}//end testDeclaredRowsMoveToTheSurvivorAndTheMoveIsRecorded()

	/**
	 * A reversal puts back exactly the rows the merge moved, and nothing else.
	 *
	 * @return void
	 */
	public function testAReversalPutsBackOnlyTheRowsTheMergeMoved(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b']);
		$this->objects->store('3', 'role-1', ['id' => 'role-1', 'case' => 'case-b']);
		$this->objects->store('3', 'role-2', ['id' => 'role-2', 'case' => 'case-a']);

		$service = $this->service();
		$service->applyMerge(mergedId: 'case-b', survivorId: 'case-a');
		$service->applyReversal(mergedId: 'case-b', survivorId: 'case-a');

		$this->assertSame('case-b', $this->objects->read('3', 'role-1')['case']);
		$this->assertSame('case-a', $this->objects->read('3', 'role-2')['case']);
		$this->assertNull($this->objects->read('2', 'case-b')['mergedInto']);
		$this->assertNull($this->objects->read('2', 'case-b')['endingAct']);
	}//end testAReversalPutsBackOnlyTheRowsTheMergeMoved()

	/**
	 * A reversal re-arms the term the merge completed.
	 *
	 * @return void
	 */
	public function testAReversalRearmsTheCompletedTerm(): void {
		$this->objects->store('2', 'case-b', ['id' => 'case-b', 'mergedInto' => 'case-a']);

		$this->terms->method('instancesForCase')->willReturn(
			[
				['id' => 'term-b', 'status' => 'completed', 'case' => 'case-b', 'endDateCurrent' => '2026-10-01', 'voltooiDatum' => '2026-09-18'],
			]
		);

		$rearmed = [];
		$this->terms->method('saveTermInstance')->willReturnCallback(
			static function (array $instance) use (&$rearmed): ?array {
				$rearmed[] = $instance;
				return $instance;
			}
		);

		$this->service()->applyReversal(mergedId: 'case-b', survivorId: 'case-a');

		$this->assertCount(1, $rearmed);
		$this->assertSame('lopend', $rearmed[0]['status']);
		$this->assertSame('2026-10-01', $rearmed[0]['endDateCurrent']);
		$this->assertArrayNotHasKey('voltooiDatum', $rearmed[0]);
	}//end testAReversalRearmsTheCompletedTerm()

	/**
	 * Scenario: A reply to the old number. The chain is followed to its end.
	 *
	 * @return void
	 */
	public function testTheSurvivorIsFoundThroughAChainOfMerges(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b', 'mergedInto' => 'case-a']);
		$this->objects->store('2', 'case-c', ['id' => 'case-c', 'mergedInto' => 'case-b']);

		$this->assertSame('case-a', $this->service()->resolveSurvivor(caseId: 'case-c'));
		$this->assertSame('case-a', $this->service()->resolveSurvivor(caseId: 'case-a'));
	}//end testTheSurvivorIsFoundThroughAChainOfMerges()

	/**
	 * A chain that points back at itself answers the id it was given rather
	 * than running for ever.
	 *
	 * @return void
	 */
	public function testACycleAnswersTheCaseItWasAsked(): void {
		$this->objects->store('2', 'case-a', ['id' => 'case-a', 'mergedInto' => 'case-b']);
		$this->objects->store('2', 'case-b', ['id' => 'case-b', 'mergedInto' => 'case-a']);

		$this->assertSame('case-a', $this->service()->resolveSurvivor(caseId: 'case-a'));
	}//end testACycleAnswersTheCaseItWasAsked()

	/**
	 * A decided case is refused as a merge source, the way a delete is
	 * refused on one.
	 *
	 * @return void
	 */
	public function testADecidedCaseIsRefusedAsAMergeSource(): void {
		$service = $this->service();

		$this->assertTrue($service->isMergeable(case: ['id' => 'case-b']));
		$this->assertFalse($service->isMergeable(case: ['id' => 'case-b', 'isFinalStatus' => true]));
		$this->assertFalse($service->isMergeable(case: ['id' => 'case-b', 'besluitDocument' => 'doc-1']));
		$this->assertFalse($service->isMergeable(case: ['id' => 'case-b', 'mergedInto' => 'case-a']));
	}//end testADecidedCaseIsRefusedAsAMergeSource()

	/**
	 * The rule is the one the register declares, not one written here.
	 *
	 * @return void
	 */
	public function testTheRuleIsReadFromTheShippedRegister(): void {
		$rule = $this->service()->mergeRule();

		$this->assertSame('case', $rule['entityType']);
		$this->assertSame('mergeState', $rule['statusField']);
		$this->assertSame('merged', $rule['mergedStatus']);
		$this->assertSame(7, $rule['reversalWindowDays']);
	}//end testTheRuleIsReadFromTheShippedRegister()

	/**
	 * The service over the fakes.
	 *
	 * @return CaseMergeService The service under test.
	 */
	private function service(): CaseMergeService {
		$config = [
			'register' => '1',
			'case_schema' => '2',
			'role_schema' => '3',
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->objects);

		// REAL collaborators over the SAME settings double the assertions read
		// through. Only the wiring lines moved when they were split out.
		$store = new CaseMergeStore($settings, new NullLogger());
		$rule = new CaseMergeRule(new NullLogger());

		return new CaseMergeService(
			settingsService: $settings,
			termijnService: $this->terms,
			store: $store,
			rule: $rule,
			relink: new CaseMergeRelink($rule, $store),
			logger: new NullLogger()
		);
	}//end service()
}//end class

/**
 * A stand-in for OpenRegister's ObjectService that stores what it is given.
 *
 * Written out as a class rather than stubbed, so a method the real service
 * does not have cannot be invented here: the three below are the three
 * {@see \OCA\Dossiq\Service\Support\SearchesObjects} actually calls.
 */
final class FakeMergeObjectService {
	/**
	 * Stored rows, as `schemaId => uuid => payload`.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Put a row in the store.
	 *
	 * @param string               $schema  The schema id.
	 * @param string               $uuid    The row uuid.
	 * @param array<string, mixed> $payload The row.
	 *
	 * @return void
	 */
	public function store(string $schema, string $uuid, array $payload): void {
		$this->rows[$schema][$uuid] = $payload;
	}//end store()

	/**
	 * Read a row back.
	 *
	 * @param string $schema The schema id.
	 * @param string $uuid   The row uuid.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function read(string $schema, string $uuid): array {
		return ($this->rows[$schema][$uuid] ?? []);
	}//end read()

	/**
	 * Find one row.
	 *
	 * @param int|string      $id       The row uuid.
	 * @param array|null      $_extend  Unused.
	 * @param bool            $files    Unused.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   The schema id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function find(
		int|string $id,
		?array $_extend = null,
		bool $files = false,
		int|string|null $register = null,
		int|string|null $schema = null,
	): ?array {
		return ($this->rows[(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Write a few fields onto one row.
	 *
	 * @param string          $objectId The row uuid.
	 * @param array           $data     The fields to write.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   The schema id.
	 *
	 * @return array<string, mixed>|null The stored row.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		int|string|null $register = null,
		int|string|null $schema = null,
	): ?array {
		$key = (string)$schema;
		if (isset($this->rows[$key][$objectId]) === false) {
			return null;
		}

		$this->rows[$key][$objectId] = array_merge($this->rows[$key][$objectId], $data);

		return $this->rows[$key][$objectId];
	}//end patchObject()

	/**
	 * Search rows of one schema.
	 *
	 * @param array<string, mixed> $query The `@self` block plus field filters.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function searchObjects(array $query): array {
		$schema = (string)($query['@self']['schema'] ?? '');
		unset($query['@self']);

		$matches = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			foreach ($query as $field => $value) {
				if ((string)($row[$field] ?? '') !== (string)$value) {
					continue 2;
				}
			}

			$matches[] = $row;
		}

		return $matches;
	}//end searchObjects()
}//end class
