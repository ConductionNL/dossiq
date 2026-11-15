<?php

/**
 * Identity, shape and label of authorization rules, tested on their own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseAuthorizationEntries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseAuthorizationEntries
 * @uses \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseAuthorizationEntriesTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var CaseAuthorizationEntries
	 */
	private CaseAuthorizationEntries $entries;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->entries = new CaseAuthorizationEntries();
	}//end setUp()

	/**
	 * Entries and whether each is shaped like a team rule.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function teamShapes(): array {
		return [
			'team rule' => [['group' => 'team-a', 'match' => ['caseType' => ['$in' => ['t1']]]], true],
			'keys in other order' => [['match' => ['caseType' => 'x'], 'group' => 'team-a'], true],
			'not an array' => ['team-a', false],
			'empty array' => [[], false],
			'group only, no match' => [['group' => 'team-a'], false],
			'user instead of group' => [['user' => 'alice', 'match' => ['caseType' => 'x']], false],
			'extra key' => [['group' => 'team-a', 'match' => ['caseType' => 'x'], 'read' => true], false],
			'group not a string' => [['group' => 7, 'match' => ['caseType' => 'x']], false],
			'match on another field' => [['group' => 'team-a', 'match' => ['assignee' => '$userId']], false],
			'match on caseType and more' => [['group' => 'team-a', 'match' => ['caseType' => 'x', 'status' => 'y']], false],
		];
	}//end teamShapes()

	/**
	 * Only a group granted on caseType and nothing else is a team rule.
	 *
	 * @param mixed $entry    The entry.
	 * @param bool  $expected Whether it is a team rule.
	 *
	 * @return void
	 *
	 * @dataProvider teamShapes
	 */
	public function testIsTeamRule(mixed $entry, bool $expected): void {
		$this->assertSame(expected: $expected, actual: $this->entries->isTeamRule(entry: $entry));
	}//end testIsTeamRule()

	/**
	 * A managed team rule is a team rule whose group is in the managed list.
	 *
	 * @return void
	 */
	public function testIsManagedTeamRule(): void {
		$rule = ['group' => 'team-a', 'match' => ['caseType' => 'x']];

		$this->assertTrue(condition: $this->entries->isManagedTeamRule(entry: $rule, teams: ['team-b', 'team-a']));
		$this->assertFalse(condition: $this->entries->isManagedTeamRule(entry: $rule, teams: ['team-b']));
		$this->assertFalse(condition: $this->entries->isManagedTeamRule(entry: ['group' => 'team-a'], teams: ['team-a']));
	}//end testIsManagedTeamRule()

	/**
	 * The teams of a block come from its list values' team rules, in order, skipping the rest.
	 *
	 * @return void
	 */
	public function testTeamsInCollectsOnlyTeamRulesFromLists(): void {
		$block = [
			'read' => [
				['group' => 'team-a', 'match' => ['caseType' => 'x']],
				['group' => 'authenticated', 'match' => ['assignee' => '$userId']],
				'admin',
			],
			'update' => [['group' => 'team-b', 'match' => ['caseType' => 'y']]],
			'delete' => ['group' => 'team-c', 'match' => ['caseType' => 'z']],
			'create' => null,
		];

		$this->assertSame(expected: ['team-a', 'team-b'], actual: $this->entries->teamsIn(block: $block));
		$this->assertSame(expected: [], actual: $this->entries->teamsIn(block: []));
	}//end testTeamsInCollectsOnlyTeamRulesFromLists()

	/**
	 * A label names the group (else the user) and the match with sorted keys.
	 *
	 * @return void
	 */
	public function testLabel(): void {
		$rule = ['group' => 'team-a', 'match' => ['caseType' => 'x', 'a' => 1]];
		$this->assertSame(expected: 'team-a:{"a":1,"caseType":"x"}', actual: $this->entries->label(entry: $rule));
		$this->assertSame(expected: 'alice:{"b":2}', actual: $this->entries->label(entry: ['user' => 'alice', 'match' => ['b' => 2]]));
		$this->assertSame(expected: 'team-a:[]', actual: $this->entries->label(entry: ['group' => 'team-a']));
		$this->assertSame(expected: ':[]', actual: $this->entries->label(entry: []));
		$this->assertSame(expected: '"admin"', actual: $this->entries->label(entry: 'admin'));
		$this->assertSame(expected: 'null', actual: $this->entries->label(entry: null));
	}//end testLabel()

	/**
	 * A key is independent of map key order at every depth, but keeps list order.
	 *
	 * @return void
	 */
	public function testKeyIsCanonical(): void {
		$one = ['match' => ['z' => 1, 'a' => ['y' => 2, 'b' => 3]], 'group' => 'g'];
		$two = ['group' => 'g', 'match' => ['a' => ['b' => 3, 'y' => 2], 'z' => 1]];

		$this->assertSame(expected: '{"group":"g","match":{"a":{"b":3,"y":2},"z":1}}', actual: $this->entries->key(entry: $one));
		$this->assertSame(expected: $this->entries->key(entry: $one), actual: $this->entries->key(entry: $two));
		$this->assertSame(expected: '["b","a"]', actual: $this->entries->key(entry: ['b', 'a']));
		$this->assertSame(expected: '"x"', actual: $this->entries->key(entry: 'x'));
	}//end testKeyIsCanonical()

	/**
	 * Null and lists are lists; maps and scalars are not.
	 *
	 * @return void
	 */
	public function testIsList(): void {
		$this->assertTrue(condition: $this->entries->isList(value: null));
		$this->assertTrue(condition: $this->entries->isList(value: []));
		$this->assertTrue(condition: $this->entries->isList(value: [['group' => 'g']]));
		$this->assertFalse(condition: $this->entries->isList(value: ['group' => 'g']));
		$this->assertFalse(condition: $this->entries->isList(value: 'g'));
		$this->assertFalse(condition: $this->entries->isList(value: [1 => 'a']));
	}//end testIsList()

	/**
	 * A list is returned as is; anything else becomes empty.
	 *
	 * @return void
	 */
	public function testListOf(): void {
		$this->assertSame(expected: ['a', 'b'], actual: $this->entries->listOf(value: ['a', 'b']));
		$this->assertSame(expected: [], actual: $this->entries->listOf(value: ['group' => 'g']));
		$this->assertSame(expected: [], actual: $this->entries->listOf(value: null));
		$this->assertSame(expected: [], actual: $this->entries->listOf(value: 'a'));
	}//end testListOf()

	/**
	 * Repeats, also with keys in another order, are dropped and the first one kept.
	 *
	 * @return void
	 */
	public function testUniqueKeepsFirstOfEachCanonicalEntry(): void {
		$first = ['group' => 'g', 'match' => ['caseType' => 'x']];
		$same = ['match' => ['caseType' => 'x'], 'group' => 'g'];
		$other = ['group' => 'h', 'match' => ['caseType' => 'x']];

		$this->assertSame(expected: [$first, $other, 'admin'], actual: $this->entries->unique(entries: [$first, $same, $other, 'admin', $first, 'admin']));
		$this->assertSame(expected: [], actual: $this->entries->unique(entries: []));
	}//end testUniqueKeepsFirstOfEachCanonicalEntry()
}//end class
