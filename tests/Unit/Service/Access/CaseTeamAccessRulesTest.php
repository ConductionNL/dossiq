<?php

/**
 * The handling team rules dossiq writes onto the case schema.
 *
 * What these pin is the block OpenRegister is handed: one conditional entry per
 * team over the case types it handles, on read and update and on nothing else.
 * Its evaluation is OpenRegister's and is asserted there, through the real
 * PermissionHandler, in PermissionHandlerHandlingTeamRuleTest.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessRulesTest extends TestCase {

	/**
	 * The shipped case block, as register.d/66-notification-routing.json declares it.
	 *
	 * @var array<string, mixed>
	 */
	private const SHIPPED = [
		'roles' => ['behandelaar' => ['behandelaars']],
		'read' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']], 'dossiq-background-service'],
		'create' => ['dossiq-medewerkers', 'dossiq-background-service'],
		'update' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']], 'dossiq-background-service'],
		'delete' => ['admin'],
	];

	/**
	 * A team reads and updates the case types it handles, and deletes nothing.
	 *
	 * @return void
	 */
	public function testATeamReadsAndUpdatesItsCaseTypesAndDeletesNothing(): void {
		$block = (new CaseTeamAccessRules())->block(
			shipped: self::SHIPPED,
			caseTypes: [['id' => 'ct-permit', 'teams' => ['vergunningen']]]
		);

		$rule = ['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];
		$this->assertContains($rule, $block['read']);
		$this->assertContains($rule, $block['update']);
		foreach (['readFiles', 'addFiles', 'deleteFiles'] as $fileVerb) {
			$this->assertContains($rule, $block[$fileVerb], 'a team keeps what it could do with the files: ' . $fileVerb);
		}

		$this->assertSame(['admin'], $block['delete']);
		$this->assertSame(self::SHIPPED['create'], $block['create']);
		$this->assertSame(self::SHIPPED['roles'], $block['roles']);
	}//end testATeamReadsAndUpdatesItsCaseTypesAndDeletesNothing()

	/**
	 * The shipped entries stay first and unchanged, so the assignee keeps the case.
	 *
	 * @return void
	 */
	public function testTheShippedEntriesStayInFront(): void {
		$block = (new CaseTeamAccessRules())->block(
			shipped: self::SHIPPED,
			caseTypes: [['id' => 'ct-permit', 'teams' => ['vergunningen']]]
		);

		$this->assertSame(self::SHIPPED['read'], array_slice($block['read'], 0, 2));
		$this->assertCount(3, $block['read']);
	}//end testTheShippedEntriesStayInFront()

	/**
	 * One entry per team, over every case type that team handles, sorted.
	 *
	 * Two case types for one team are one rule with two ids, not two rules; and
	 * the order is fixed, so the same case types give the same block and the
	 * projector writes nothing when nothing changed.
	 *
	 * @return void
	 */
	public function testOneEntryPerTeamOverItsCaseTypesInAFixedOrder(): void {
		$entries = (new CaseTeamAccessRules())->teamEntries(
			caseTypes: [
				['id' => 'ct-b', 'teams' => ['vergunningen', 'handhaving']],
				['id' => 'ct-a', 'teams' => ['vergunningen']],
			]
		);

		$this->assertSame(
			[
				['group' => 'handhaving', 'match' => ['caseType' => ['$in' => ['ct-b']]]],
				['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-a', 'ct-b']]]],
			],
			$entries
		);
	}//end testOneEntryPerTeamOverItsCaseTypesInAFixedOrder()

	/**
	 * A case type without an id or a team grants nobody anything.
	 *
	 * An empty group id would be a rule matching nobody, and an empty case type
	 * id in the list would be a case with no type readable by the team.
	 *
	 * @return void
	 */
	public function testNoIdAndNoTeamGrantNothing(): void {
		$rules = new CaseTeamAccessRules();

		$this->assertSame(
			[],
			$rules->teamEntries(caseTypes: [['id' => '', 'teams' => ['vergunningen']], ['id' => 'ct', 'teams' => ['', ' ']]])
		);
		$this->assertSame(self::SHIPPED, $rules->block(shipped: self::SHIPPED, caseTypes: []));
	}//end testNoIdAndNoTeamGrantNothing()

	/**
	 * A team taken off its last case type loses its rule.
	 *
	 * The block is computed again from the case types as they are now, so there
	 * is nothing left over from the run before.
	 *
	 * @return void
	 */
	public function testATeamTakenOffLosesItsRule(): void {
		$rules = new CaseTeamAccessRules();
		$before = $rules->block(shipped: self::SHIPPED, caseTypes: [['id' => 'ct', 'teams' => ['vergunningen']]]);
		$after = $rules->block(shipped: self::SHIPPED, caseTypes: [['id' => 'ct', 'teams' => []]]);

		$this->assertNotSame($before, $after);
		$this->assertSame(self::SHIPPED, $after);
	}//end testATeamTakenOffLosesItsRule()
}//end class
