<?php

/**
 * The projector's block folded onto the live case schema.
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

use OCA\Dossiq\Service\Access\CaseAuthorizationMerge;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseAuthorizationMerge
 * @uses \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseAuthorizationMergeTest extends TestCase {

	private const ASSIGNEE = ['group' => 'authenticated', 'match' => ['assignee' => '$userId']];

	/**
	 * A team rule over the given case types.
	 *
	 * @param string             $group     The team.
	 * @param array<int, string> $caseTypes The case types.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private static function team(string $group, array $caseTypes): array {
		return ['group' => $group, 'match' => ['caseType' => ['$in' => $caseTypes]]];
	}//end team()

	/**
	 * Merge one action's lists.
	 *
	 * @param array<int, mixed>      $desired  What the projector derives now.
	 * @param array<int, mixed>      $live     The live list.
	 * @param array<int, mixed>|null $baseline What the projector wrote last, or null.
	 *
	 * @return array{block: array<string, mixed>, conflicts: array<int, string>} The result.
	 */
	private static function merge(array $desired, array $live, ?array $baseline): array {
		$recorded = null;
		if ($baseline !== null) {
			$recorded = ['read' => $baseline];
		}

		return (new CaseAuthorizationMerge())->merge(desired: ['read' => $desired], live: ['read' => $live], baseline: $recorded);
	}//end merge()

	/**
	 * An administrator's rule survives, beside the projector's.
	 *
	 * @return void
	 */
	public function testALocalRuleSurvives(): void {
		$owned = [self::ASSIGNEE, self::team(group: 'vergunningen', caseTypes: ['ct-permit'])];
		$local = ['group' => 'juristen', 'match' => ['status' => 'bezwaar']];

		$result = self::merge(desired: $owned, live: array_merge($owned, [$local]), baseline: $owned);

		self::assertSame(expected: array_merge($owned, [$local]), actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testALocalRuleSurvives()

	/**
	 * A local rule survives an import that dropped the team rules, and the team rules come back.
	 *
	 * After an import the live block is the shipped one with the local rule
	 * kept, and the baseline is the shipped one.
	 *
	 * @return void
	 */
	public function testALocalRuleSurvivesAnImport(): void {
		$local = ['group' => 'juristen', 'match' => ['status' => 'bezwaar']];
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE, $local], baseline: [self::ASSIGNEE]);

		self::assertSame(expected: [self::ASSIGNEE, $team, $local], actual: $result['block']['read']);
	}//end testALocalRuleSurvivesAnImport()

	/**
	 * A projector rule an administrator changed is kept as they left it, and reported.
	 *
	 * @return void
	 */
	public function testAChangedProjectorRuleIsReportedNotOverwritten(): void {
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);
		$edited = self::team(group: 'vergunningen', caseTypes: ['ct-permit', 'ct-kap']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE, $edited], baseline: [self::ASSIGNEE, $team]);

		self::assertSame(expected: [self::ASSIGNEE, $edited], actual: $result['block']['read']);
		self::assertSame(expected: ['read[vergunningen:caseType]'], actual: $result['conflicts']);
	}//end testAChangedProjectorRuleIsReportedNotOverwritten()

	/**
	 * A projector rule an administrator removed stays removed, and is reported.
	 *
	 * @return void
	 */
	public function testARemovedProjectorRuleStaysRemoved(): void {
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE], baseline: [self::ASSIGNEE, $team]);

		self::assertSame(expected: [self::ASSIGNEE], actual: $result['block']['read']);
		self::assertSame(expected: ['read[vergunningen:caseType]'], actual: $result['conflicts']);
	}//end testARemovedProjectorRuleStaysRemoved()

	/**
	 * The projector's own rules still update: a team taken off a case type loses its rule.
	 *
	 * @return void
	 */
	public function testTheProjectorsOwnRulesStillUpdate(): void {
		$before = [self::ASSIGNEE, self::team(group: 'vergunningen', caseTypes: ['ct-permit'])];
		$after = [self::ASSIGNEE, self::team(group: 'handhaving', caseTypes: ['ct-permit'])];

		$result = self::merge(desired: $after, live: $before, baseline: $before);

		self::assertSame(expected: $after, actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testTheProjectorsOwnRulesStillUpdate()

	/**
	 * No local rule is widened: a team rule for the same group is added beside it, never folded in.
	 *
	 * @return void
	 */
	public function testNoLocalRuleIsWidened(): void {
		$local = ['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-permit']], 'confidentiality' => 'intern']];
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-other']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE, $local], baseline: [self::ASSIGNEE]);

		self::assertSame(expected: [self::ASSIGNEE, $team, $local], actual: $result['block']['read']);
		self::assertContains(needle: $local, haystack: $result['block']['read']);
	}//end testNoLocalRuleIsWidened()

	/**
	 * Without a baseline, team-shaped and shipped rules are the projector's; the rest is local, and nothing conflicts.
	 *
	 * @return void
	 */
	public function testWithoutABaselineTheShapeDecides(): void {
		$stale = self::team(group: 'oud-team', caseTypes: ['ct-permit']);
		$local = ['group' => 'juristen', 'match' => ['status' => 'bezwaar']];
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE, $stale, $local], baseline: null);

		self::assertSame(expected: [self::ASSIGNEE, $team, $local], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testWithoutABaselineTheShapeDecides()

	/**
	 * A map is the projector's while the live value is what it wrote; an administrator's change is kept and reported.
	 *
	 * @return void
	 */
	public function testAMapIsTakenWholeOrKeptLocal(): void {
		$merge = new CaseAuthorizationMerge();
		$shipped = ['behandelaar' => ['behandelaars']];
		$next = ['behandelaar' => ['behandelaars'], 'coordinator' => ['coordinatoren']];
		$edited = ['behandelaar' => ['juristen']];

		$clean = $merge->merge(desired: ['roles' => $next], live: ['roles' => $shipped], baseline: ['roles' => $shipped]);
		self::assertSame(expected: ['roles' => $next], actual: $clean['block']);

		$kept = $merge->merge(desired: ['roles' => $next], live: ['roles' => $edited], baseline: ['roles' => $shipped]);
		self::assertSame(expected: ['roles' => $edited], actual: $kept['block']);
		self::assertSame(expected: ['roles'], actual: $kept['conflicts']);
	}//end testAMapIsTakenWholeOrKeptLocal()

	/**
	 * A changed projector rule stays the administrator's even when the projector derives a new one for that team.
	 *
	 * @return void
	 */
	public function testAChangedRuleIsNotOverwrittenByTheTeamsNextRule(): void {
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);
		$edited = self::team(group: 'vergunningen', caseTypes: ['ct-permit', 'ct-kap']);
		$next = self::team(group: 'vergunningen', caseTypes: ['ct-permit', 'ct-nieuw']);

		$result = self::merge(desired: [$next], live: [$edited], baseline: [$team]);

		self::assertSame(expected: [$edited], actual: $result['block']['read']);
		self::assertSame(expected: ['read[vergunningen:caseType]'], actual: $result['conflicts']);
	}//end testAChangedRuleIsNotOverwrittenByTheTeamsNextRule()

	/**
	 * A local rule the projector now derives too is written once.
	 *
	 * @return void
	 */
	public function testARuleOnBothSidesIsWrittenOnce(): void {
		$team = self::team(group: 'handhaving', caseTypes: ['ct-permit']);

		$result = self::merge(desired: [self::ASSIGNEE, $team], live: [self::ASSIGNEE, $team], baseline: [self::ASSIGNEE]);

		self::assertSame(expected: [self::ASSIGNEE, $team], actual: $result['block']['read']);
	}//end testARuleOnBothSidesIsWrittenOnce()

	/**
	 * Key order inside a rule does not make it a different rule.
	 *
	 * @return void
	 */
	public function testKeyOrderDoesNotMakeARuleLocal(): void {
		$team = self::team(group: 'vergunningen', caseTypes: ['ct-permit']);
		$reordered = ['match' => ['caseType' => ['$in' => ['ct-permit']]], 'group' => 'vergunningen'];

		$result = self::merge(desired: [$team], live: [$reordered], baseline: [$team]);

		self::assertSame(expected: [$team], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testKeyOrderDoesNotMakeARuleLocal()
}//end class
