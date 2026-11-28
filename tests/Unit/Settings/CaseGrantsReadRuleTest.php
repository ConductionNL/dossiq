<?php

/**
 * Who may read a case's grants list: its assignee and the background service.
 *
 * OpenRegister's `GET /api/objects/dossiq/case/{id}/grants` answers only for a
 * caller the case schema's `readGrants` rule admits (beside the owner,
 * administrators and grant_manage holders), and that verb never resolves
 * default-open. Without the rule the Toegang card's grant rows are a refusal
 * for everybody the case is assigned to.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/case-access-card-lists-team-and-group-grants/specs/case-page/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @spec openspec/changes/case-access-card-lists-team-and-group-grants/specs/case-page/spec.md
 */
class CaseGrantsReadRuleTest extends TestCase {

	/**
	 * The assignee entry the case's read and update rules carry.
	 *
	 * @var array<string, mixed>
	 */
	private const ASSIGNEE = ['group' => 'authenticated', 'match' => ['assignee' => '$userId']];

	/**
	 * The case schema's authorization block as the import ships it.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function shippedCaseBlock(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		return $merged['components']['schemas']['case']['authorization'];
	}//end shippedCaseBlock()

	/**
	 * The assignee and the background service may read the grants, and nobody else through the rule.
	 *
	 * @return void
	 */
	public function testTheAssigneeAndTheBackgroundServiceReadTheGrants(): void {
		$this->assertSame([self::ASSIGNEE, 'dossiq-background-service'], $this->shippedCaseBlock()['readGrants']);
	}//end testTheAssigneeAndTheBackgroundServiceReadTheGrants()

	/**
	 * The people who read the case's grants are the people who read the case itself as its assignee.
	 *
	 * @return void
	 */
	public function testTheRuleMatchesTheCasesOwnAssigneeRule(): void {
		$block = $this->shippedCaseBlock();

		$this->assertContains(self::ASSIGNEE, $block['read']);
		$this->assertContains(self::ASSIGNEE, $block['readGrants']);
	}//end testTheRuleMatchesTheCasesOwnAssigneeRule()

	/**
	 * A recompute of the handling team rules keeps the rule, and adds no team to it.
	 *
	 * @return void
	 */
	public function testAHandlingTeamRecomputeKeepsTheRule(): void {
		$shipped = $this->shippedCaseBlock();

		$block = (new CaseTeamAccessRules())->block(
			shipped: $shipped,
			caseTypes: [['id' => 'ct-woo', 'teams' => ['woo-team']]]
		);

		$this->assertSame($shipped['readGrants'], $block['readGrants']);
		$this->assertNotSame($shipped['read'], $block['read'], 'the control: the recompute did add the team to read');
	}//end testAHandlingTeamRecomputeKeepsTheRule()
}//end class
