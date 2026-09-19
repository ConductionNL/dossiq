<?php

/**
 * Carrying out a split, against a real register.
 *
 * `CaseSplitPolicy` and `CaseSplitPlan` shipped pure and already have their own
 * suites. This one does NOT re-test either: it watches the thing neither of
 * them could, which is whether anything actually moved. A plan that names two
 * documents and an executor that writes nothing produce exactly the same plan.
 *
 * So every assertion here is about BOTH halves after the write, never about the
 * new case alone: a test that checked only the new case would pass on a copy,
 * which is the defect the whole change exists to end.
 *
 * MUTATION-CHECKED 2026-09-18: passing `uuid: null` in `apply()` turns the move
 * into a create and reddens testTheChosenDocumentsLeaveTheOriginal on the
 * original's count; dropping the `in_array($id, $wanted)` filter in
 * `rowsForPart()` reddens the same test, because everything goes. Restored
 * after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitStore;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What moves, what stays, what is on both, and what is refused.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitExecutor
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPlan
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitExecutorTest extends TestCase {

	/**
	 * The store the executor reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A case with four documents and two parties.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-1', row: ['title' => 'Melding openbare ruimte']);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Twee klachten op één formulier', 'caseType' => 'ct-1', 'identifier' => 'ZAAK-1'],
		);

		foreach (['doc-1', 'doc-2', 'doc-3', 'doc-4'] as $id) {
			$this->store->seed(schema: 'caseDocument', uuid: $id, row: ['case' => 'case-1', 'title' => $id]);
		}

		foreach (['role-1', 'role-2'] as $id) {
			$this->store->seed(
				schema: 'role',
				uuid: $id,
				row: ['case' => 'case-1', 'roleType' => 'belanghebbende', 'name' => $id],
			);
		}
	}//end setUp()

	/**
	 * The chosen documents leave the original and arrive on the new case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testTheChosenDocumentsLeaveTheOriginal(): void {
		$answer = $this->executor()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1', 'doc-2'], 'parties' => ['role-1']],
			actor: 'jan',
		);

		$newId = (string)$answer['case']['id'];

		self::assertSame(['doc-1', 'doc-2'], $this->idsOn(schema: 'caseDocument', caseId: $newId));
		self::assertSame(
			['doc-3', 'doc-4'],
			$this->idsOn(schema: 'caseDocument', caseId: 'case-1'),
			'A plan that names two documents and an executor that writes nothing produce the same plan.',
		);
		self::assertSame(['role-1'], $this->idsOn(schema: 'role', caseId: $newId));
		self::assertSame(['role-2'], $this->idsOn(schema: 'role', caseId: 'case-1'));
	}//end testTheChosenDocumentsLeaveTheOriginal()

	/**
	 * The original records what left it, including the plan's own note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testTheOriginalRecordsWhatLeftIt(): void {
		$answer = $this->executor()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1']],
			actor: 'jan',
		);

		$original = $this->store->row(schema: 'case', uuid: 'case-1');
		$newId = (string)$answer['case']['id'];

		self::assertSame($newId, $original['splitInto']);
		self::assertCount(1, $original['splitMovedItems']);
		self::assertSame('doc-1', $original['splitMovedItems'][0]['id']);
		self::assertSame($newId, $original['splitMovedItems'][0]['movedTo']);
		self::assertNotSame(
			'',
			(string)$original['splitNote'],
			'CaseSplitPlan::noteFor() produced a sentence and until now nothing stored it.',
		);
		self::assertSame($original['splitNote'], $answer['note']);
	}//end testTheOriginalRecordsWhatLeftIt()

	/**
	 * A party relevant to both halves is on both, with its role intact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testAPartyRelevantToBothHalvesStaysOnBoth(): void {
		$answer = $this->executor()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1'], 'partiesOnBoth' => ['role-1']],
			actor: 'jan',
		);

		$newId = (string)$answer['case']['id'];

		self::assertContains('role-1', $this->idsOn(schema: 'role', caseId: 'case-1'), 'It did not leave.');
		$onNew = $this->rowsOn(schema: 'role', caseId: $newId);
		self::assertCount(1, $onNew, 'And it is present on the new case too.');
		self::assertSame(
			'belanghebbende',
			$onNew[0]['roleType'],
			'A party on a case is a role, so the role has to come with it.',
		);
	}//end testAPartyRelevantToBothHalvesStaysOnBoth()

	/**
	 * The refusal is the policy's own sentence, not a second one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
	 */
	public function testTheCaseTypesRefusalIsThePolicysOwnSentence(): void {
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['title' => 'Melding openbare ruimte', 'splittableParts' => ['parties']],
		);

		$expected = (new CaseSplitPolicy())->whyRefused(
			selected: ['documents'],
			caseType: ['splittableParts' => ['parties']],
		);

		try {
			$this->executor()->split(
				caseId: 'case-1',
				title: 'De tweede klacht',
				chosen: ['documents' => ['doc-1']],
				actor: 'jan',
			);
			self::fail('A split of something the case type forbids must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseSplitExecutor::FORBIDDEN, $e->getRule());
			self::assertSame(
				$expected,
				$e->getSentence(),
				'A refusal written twice is a refusal that will eventually say two different things.',
			);
		}

		self::assertCount(4, $this->idsOn(schema: 'caseDocument', caseId: 'case-1'), 'And nothing moved.');
		self::assertCount(1, $this->store->all(schema: 'case'), 'And no second case was opened.');
	}//end testTheCaseTypesRefusalIsThePolicysOwnSentence()

	/**
	 * A task is declarable and not movable, and the refusal says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testMovingATaskIsRefusedWithItsReason(): void {
		try {
			$this->executor()->split(
				caseId: 'case-1',
				title: 'De tweede klacht',
				chosen: ['tasks' => ['task-1']],
				actor: 'jan',
			);
			self::fail('A task cannot be moved, so asking must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseSplitExecutor::UNMOVABLE, $e->getRule());
			self::assertStringContainsString(
				'engine has no verb',
				$e->getSentence(),
				'Accepting the choice and moving nothing is the silent no-op this refusal exists to avoid.',
			);
		}
	}//end testMovingATaskIsRefusedWithItsReason()

	/**
	 * An id belonging to another case is not moved by naming it.
	 *
	 * The plan refuses it, and it can only do that because the executor hands
	 * it the row's STORED `case` rather than the id the client sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testAnIdOnAnotherCaseIsNotMoved(): void {
		$this->store->seed(schema: 'case', uuid: 'case-2', row: ['title' => 'Andermans zaak', 'caseType' => 'ct-1']);
		$this->store->seed(schema: 'caseDocument', uuid: 'doc-9', row: ['case' => 'case-2', 'title' => 'doc-9']);

		$answer = $this->executor()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1', 'doc-9']],
			actor: 'jan',
		);

		self::assertSame(
			['doc-9'],
			$this->idsOn(schema: 'caseDocument', caseId: 'case-2'),
			'A handler could otherwise move a document off somebody else\'s case by editing one field.',
		);
		self::assertSame(['doc-1'], $this->idsOn(schema: 'caseDocument', caseId: (string)$answer['case']['id']));
	}//end testAnIdOnAnotherCaseIsNotMoved()

	/**
	 * A split that divides nothing is refused before a second case is opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testASplitThatDividesNothingIsRefused(): void {
		try {
			$this->executor()->split(caseId: 'case-1', title: 'Leeg', chosen: [], actor: 'jan');
			self::fail('A split with nothing chosen must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseSplitExecutor::NOTHING_CHOSEN, $e->getRule());
		}

		self::assertCount(
			1,
			$this->store->all(schema: 'case'),
			'An empty second case somebody has to notice and close is worse than a refusal.',
		);
	}//end testASplitThatDividesNothingIsRefused()

	/**
	 * The new case names the original, in the shape the Related tab reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function testTheNewCaseNamesTheOriginal(): void {
		$answer = $this->executor()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1']],
			actor: 'jan',
		);

		$related = ($answer['case']['relatedCases'] ?? null);
		self::assertIsString(
			$related,
			'`relatedCases` is a JSON-encoded string of typed relations; an array stores a shape nothing reads.',
		);

		$decoded = json_decode((string)$related, true);
		self::assertSame('case-1', $decoded[0]['caseId']);
		self::assertSame(CaseSplitPlan::RELATION, $decoded[0]['aardRelatie']);
		self::assertSame('case-1', (string)$answer['case']['splitFrom']);
	}//end testTheNewCaseNamesTheOriginal()

	/**
	 * The ids of one schema's rows on one case, sorted.
	 *
	 * @param string $schema The schema slug.
	 * @param string $caseId The case.
	 *
	 * @return array<int, string> The ids.
	 */
	private function idsOn(string $schema, string $caseId): array {
		$ids = array_map(
			static function (array $row): string {
				return (string)$row['id'];
			},
			$this->rowsOn(schema: $schema, caseId: $caseId),
		);
		sort($ids);

		return $ids;
	}//end idsOn()

	/**
	 * One schema's rows on one case.
	 *
	 * @param string $schema The schema slug.
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOn(string $schema, string $caseId): array {
		return array_values(
			array_filter(
				$this->store->all(schema: $schema),
				static function (array $row) use ($caseId): bool {
					return ((string)($row['case'] ?? '') === $caseId);
				},
			)
		);
	}//end rowsOn()

	/**
	 * The executor under test, over the shipped policy and plan.
	 *
	 * Real ones rather than doubles: the point of this suite is that the two
	 * are actually consulted, and a double would let an executor that decided
	 * for itself pass.
	 *
	 * @return CaseSplitExecutor The executor.
	 */
	private function executor(): CaseSplitExecutor {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'case_document_schema' => 'caseDocument',
					'role_schema' => 'role',
				];

				return ($map[$key] ?? $default);
			}
		);

		return new CaseSplitExecutor(
			policy: new CaseSplitPolicy(),
			plan: new CaseSplitPlan(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			// A REAL store over the SAME settings double the assertions read
			// through. Only the wiring line moved when it was split out.
			store: new CaseSplitStore($settings),
		);
	}//end executor()
}//end class
