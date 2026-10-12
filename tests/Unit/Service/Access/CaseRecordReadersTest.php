<?php

/**
 * Case Record Readers Test
 *
 * Who reads one private record of a case, from the `readers` rule its case
 * type declares for it: everyone on the case, the assignee only, or the
 * assignee and the reviewers the record itself names.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseRecordReaders;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseRecordReaders
 */
class CaseRecordReadersTest extends TestCase {

	private const REVIEWERS = ['b-1' => ['reviewer1'], 'b-2' => ['reviewer2', ' reviewer3 ']];

	private CaseRecordReaders $readers;

	protected function setUp(): void {
		$this->readers = new CaseRecordReaders();
	}//end setUp()

	/**
	 * The readers of one record under a declared entry.
	 *
	 * @param array<string, mixed> $declared   The `reviewerAccess.records` entry.
	 * @param array<string, mixed> $record     The record row.
	 * @param bool                 $hideOthers Whether reviewers are hidden from each other.
	 * @param string               $assignee   The case assignee.
	 *
	 * @return list<string>
	 */
	private function readersOf(array $declared, array $record = [], bool $hideOthers = false, string $assignee = 'handler'): array {
		return $this->readers->readersFor(
			rule: $this->readers->ruleOf(declared: $declared),
			record: $record,
			assignee: $assignee,
			reviewers: self::REVIEWERS,
			hideOthers: $hideOthers,
		);
	}//end readersOf()

	public function testAnEntryWithoutARuleSharesWithEveryoneOnTheCase(): void {
		self::assertSame(['readers' => 'all', 'userField' => '', 'reviewerRowField' => ''], $this->readers->ruleOf(declared: []));
		self::assertSame(['handler', 'reviewer1', 'reviewer2', 'reviewer3'], $this->readersOf(declared: []));
	}//end testAnEntryWithoutARuleSharesWithEveryoneOnTheCase()

	public function testHidingReviewersLeavesTheAssignee(): void {
		self::assertSame(['handler'], $this->readersOf(declared: ['readers' => 'all'], hideOthers: true));
	}//end testHidingReviewersLeavesTheAssignee()

	public function testTheAssigneeRuleReachesNoReviewer(): void {
		self::assertSame(['handler'], $this->readersOf(declared: ['readers' => 'assignee', 'userField' => 'assignee'], record: ['assignee' => 'reviewer1']));
	}//end testTheAssigneeRuleReachesNoReviewer()

	public function testAnUnknownRuleIsReadAsTheNarrowOne(): void {
		self::assertSame('assignee', $this->readers->ruleOf(declared: ['readers' => 'everybody'])['readers']);
		self::assertSame(['handler'], $this->readersOf(declared: ['readers' => 'everybody']));
	}//end testAnUnknownRuleIsReadAsTheNarrowOne()

	public function testOwnReachesTheUserTheRecordNames(): void {
		$declared = ['readers' => 'own', 'userField' => ' assignee '];

		self::assertSame(['handler', 'reviewer1'], $this->readersOf(declared: $declared, record: ['assignee' => 'reviewer1']));
		self::assertSame(['handler', 'a', 'b'], $this->readersOf(declared: $declared, record: ['assignee' => ['a', 'b', ['nested']]]));
		self::assertSame(['handler'], $this->readersOf(declared: $declared, record: []));
	}//end testOwnReachesTheUserTheRecordNames()

	public function testOwnReachesTheReviewersOfTheRowTheRecordNames(): void {
		$declared = ['readers' => 'own', 'reviewerRowField' => 'batch'];

		self::assertSame(['handler', 'reviewer2', 'reviewer3'], $this->readersOf(declared: $declared, record: ['batch' => 'b-2']));
		self::assertSame(['handler', 'reviewer1', 'reviewer2', 'reviewer3'], $this->readersOf(declared: $declared, record: ['batch' => ['b-1', 'b-2']]));
		self::assertSame(['handler', 'reviewer1'], $this->readersOf(declared: $declared, record: ['batch' => ['id' => 'b-1', 'name' => 'b-2']]), 'a serialised row is read by its id only');
		self::assertSame(['handler', 'reviewer1'], $this->readersOf(declared: $declared, record: ['batch' => [['uuid' => 'b-1']]]));
		self::assertSame(['handler'], $this->readersOf(declared: $declared, record: ['batch' => 'b-of-another-case']));
		self::assertSame(['handler'], $this->readersOf(declared: $declared, record: ['batch' => null]));
	}//end testOwnReachesTheReviewersOfTheRowTheRecordNames()

	public function testOwnWithoutAFieldReachesTheAssigneeOnly(): void {
		self::assertSame(['handler'], $this->readersOf(declared: ['readers' => 'own'], record: ['assignee' => 'reviewer1', 'batch' => 'b-1']));
	}//end testOwnWithoutAFieldReachesTheAssigneeOnly()

	public function testACaseWithoutAnAssigneeHasNoEmptyReader(): void {
		self::assertSame(['reviewer1'], $this->readersOf(declared: ['readers' => 'own', 'userField' => 'assignee'], record: ['assignee' => 'reviewer1'], assignee: ''));
	}//end testACaseWithoutAnAssigneeHasNoEmptyReader()
}//end class
