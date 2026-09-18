<?php

/**
 * Dividing one case in two.
 *
 * The failure this watches for is the one the row was opened on: a "split"
 * that copies, leaving two cases that both claim the same document. So the
 * assertions are about BOTH halves after the act, never about the new case
 * alone. A test that only checked the new case would pass on a copy.
 *
 * The store is a real in-memory register rather than a per-call stub, because
 * every assertion here is about which case a row names AFTER a write. A stub
 * answering the same row whatever was saved would pass a move that moved
 * nothing.
 *
 * MUTATION-CHECKED 2026-09-18: turning the `repoint()` save into a create (by
 * passing `uuid: null`) reddens testADocumentGoesToOneHalfAndNotTheOther on the
 * original's document count, because the row is then copied rather than moved.
 * Dropping the `in_array($id, $wanted)` check reddens the same test, because
 * every document goes. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Split\CaseSplitService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What moves, what stays, what is on both, and what is refused.
 *
 * @covers \OCA\Dossiq\Service\Split\CaseSplitService
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitServiceTest extends TestCase {

	/**
	 * The store the service reads and writes.
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
			row: [
				'title' => 'Twee klachten op één formulier',
				'caseType' => 'ct-1',
				'assignedGroup' => 'toezicht',
				'assignee' => 'jan',
			],
		);

		foreach (['doc-1', 'doc-2', 'doc-3', 'doc-4'] as $id) {
			$this->store->seed(schema: 'caseDocument', uuid: $id, row: ['case' => 'case-1', 'title' => $id]);
		}

		foreach (['role-1', 'role-2'] as $id) {
			$this->store->seed(schema: 'role', uuid: $id, row: ['case' => 'case-1', 'roleType' => 'belanghebbende', 'name' => $id]);
		}
	}//end setUp()

	/**
	 * Two documents and one party go; the rest stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testADocumentGoesToOneHalfAndNotTheOther(): void {
		$answer = $this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1', 'doc-2'], 'parties' => ['role-1']],
			actor: 'jan',
		);

		$newId = (string)$answer['case']['id'];

		self::assertSame(
			['doc-1', 'doc-2'],
			$this->idsOn(schema: 'caseDocument', caseId: $newId),
			'The new case holds what was chosen.',
		);
		self::assertSame(
			['doc-3', 'doc-4'],
			$this->idsOn(schema: 'caseDocument', caseId: 'case-1'),
			'And the original holds the rest, which is what makes this a split and not a copy.',
		);
		self::assertSame(['role-1'], $this->idsOn(schema: 'role', caseId: $newId));
		self::assertSame(['role-2'], $this->idsOn(schema: 'role', caseId: 'case-1'));
	}//end testADocumentGoesToOneHalfAndNotTheOther()

	/**
	 * The original says what left it, and where it went.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testTheOriginalKeepsAReferenceToWhatMoved(): void {
		$answer = $this->splits()->split(
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
		self::assertSame(
			$newId,
			$original['splitMovedItems'][0]['movedTo'],
			'Once a document has moved it no longer names this case, so nothing could reconstruct what left.',
		);
	}//end testTheOriginalKeepsAReferenceToWhatMoved()

	/**
	 * A party relevant to both halves is on both, with its role intact.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testAPartyRelevantToBothHalvesStaysOnBoth(): void {
		$answer = $this->splits()->split(
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
	 * A case type that forbids dividing documents refuses, and names the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-spl-03
	 */
	public function testACaseTypeCanForbidDividingDocuments(): void {
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['title' => 'Melding openbare ruimte', 'splitMayDivide' => ['parties']],
		);

		try {
			$this->splits()->split(
				caseId: 'case-1',
				title: 'De tweede klacht',
				chosen: ['documents' => ['doc-1']],
				actor: 'jan',
			);
			self::fail('A split of something the case type forbids must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseSplitService::FORBIDDEN, $e->getRule());
			self::assertStringContainsString('documents', $e->getSentence(), 'The refusal names what it refused.');
		}

		self::assertCount(4, $this->idsOn(schema: 'caseDocument', caseId: 'case-1'), 'And nothing moved.');
		self::assertCount(
			1,
			$this->store->all(schema: 'case'),
			'And no second case was opened: an empty one somebody has to notice and close is worse than a refusal.',
		);
	}//end testACaseTypeCanForbidDividingDocuments()

	/**
	 * An absent declaration allows all three, so nothing changes for anybody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-spl-03
	 */
	public function testAnUndeclaredCaseTypeAllowsEverything(): void {
		self::assertSame(CaseSplitService::DIVISIBLE, $this->splits()->divisibleFor(caseType: []));
		self::assertSame(
			['parties'],
			$this->splits()->divisibleFor(caseType: ['splitMayDivide' => ['parties', 'nonsense']]),
			'A kind nobody declared is not a kind.',
		);
	}//end testAnUndeclaredCaseTypeAllowsEverything()

	/**
	 * Moving a task is refused, and the refusal says why rather than doing nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testMovingATaskIsRefusedWithItsReason(): void {
		$this->expectException(RefusedException::class);

		$this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['tasks' => ['task-1']],
			actor: 'jan',
		);
	}//end testMovingATaskIsRefusedWithItsReason()

	/**
	 * A split that divides nothing is refused before a second case is opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testASplitThatDividesNothingIsRefused(): void {
		try {
			$this->splits()->split(caseId: 'case-1', title: 'Leeg', chosen: [], actor: 'jan');
			self::fail('A split with nothing chosen must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseSplitService::NOTHING_CHOSEN, $e->getRule());
		}

		self::assertCount(
			1,
			$this->store->all(schema: 'case'),
			'An empty second case is worse than a refusal: somebody has to notice it and close it.',
		);
	}//end testASplitThatDividesNothingIsRefused()

	/**
	 * An id belonging to another case is not moved by naming it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	public function testAnIdOnAnotherCaseIsNotMoved(): void {
		$this->store->seed(schema: 'case', uuid: 'case-2', row: ['title' => 'Somebody else\'s case', 'caseType' => 'ct-1']);
		$this->store->seed(schema: 'caseDocument', uuid: 'doc-9', row: ['case' => 'case-2', 'title' => 'doc-9']);

		$answer = $this->splits()->split(
			caseId: 'case-1',
			title: 'De tweede klacht',
			chosen: ['documents' => ['doc-1', 'doc-9']],
			actor: 'jan',
		);

		self::assertSame(
			['doc-9'],
			$this->idsOn(schema: 'caseDocument', caseId: 'case-2'),
			'Taking the ids on trust would move a document off somebody else\'s case by naming it.',
		);
		self::assertSame(['doc-1'], $this->idsOn(schema: 'caseDocument', caseId: (string)$answer['case']['id']));
	}//end testAnIdOnAnotherCaseIsNotMoved()

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
	 * The service under test.
	 *
	 * @return CaseSplitService The service.
	 */
	private function splits(): CaseSplitService {
		return new CaseSplitService(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end splits()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
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

		return $settings;
	}//end settings()
}//end class
