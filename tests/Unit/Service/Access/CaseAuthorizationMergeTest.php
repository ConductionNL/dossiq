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
 * @covers \OCA\Dossiq\Service\Access\CaseAuthorizationEntries
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
	 * A shipped rule that is not a team rule.
	 */
	private const KCC = ['group' => 'kcc', 'match' => ['confidentiality' => 'openbaar']];

	/**
	 * Merge one action's lists.
	 *
	 * @param array<int, mixed>      $desired  What the projector derives now.
	 * @param array<int, mixed>      $live     The live list.
	 * @param array<int, mixed>|null $baseline What the projector wrote last, or null.
	 * @param array<int, string>     $managed  The teams the projector managed before.
	 *
	 * @return array{block: array<string, mixed>, conflicts: array<int, string>} The result.
	 */
	private static function merge(array $desired, array $live, ?array $baseline, array $managed = []): array {
		$recorded = null;
		if ($baseline !== null) {
			$recorded = ['read' => $baseline];
		}

		return (new CaseAuthorizationMerge())->merge(
			desired: ['read' => $desired],
			live: ['read' => $live],
			baseline: $recorded,
			managed: $managed,
		);
	}//end merge()

	/**
	 * An administrator's rule survives a case type save, beside the projector's updated rule.
	 *
	 * @return void
	 */
	public function testALocalRuleSurvivesASave(): void {
		$owned = [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])];
		$local = ['group' => 'juristen', 'match' => ['status' => 'bezwaar']];

		$result = self::merge(
			desired: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1', 'ct2'])],
			live: array_merge($owned, [$local]),
			baseline: $owned,
		);

		self::assertSame(expected: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1', 'ct2']), $local], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testALocalRuleSurvivesASave()

	/**
	 * A local rule survives an import that dropped the team rules, and the team rules come back.
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
	 * P2: an import reset the baseline to the file's block and the team left every case type: its rule goes.
	 *
	 * @return void
	 */
	public function testAStaleTeamRuleGoesAfterAnImportBaseline(): void {
		$result = self::merge(
			desired: [self::ASSIGNEE],
			live: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])],
			baseline: [self::ASSIGNEE],
			managed: ['t1'],
		);

		self::assertSame(expected: [self::ASSIGNEE], actual: $result['block']['read']);
	}//end testAStaleTeamRuleGoesAfterAnImportBaseline()

	/**
	 * P3: the last baseline write failed, so the baseline lags; a team removed since still loses its rule.
	 *
	 * @return void
	 */
	public function testAStaleTeamRuleGoesWithALaggingBaseline(): void {
		$result = self::merge(
			desired: [self::ASSIGNEE],
			live: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])],
			baseline: [self::ASSIGNEE],
			managed: ['t1'],
		);

		self::assertNotContains(needle: self::team(group: 't1', caseTypes: ['ct1']), haystack: $result['block']['read']);
	}//end testAStaleTeamRuleGoesWithALaggingBaseline()

	/**
	 * A team the baseline shows the projector wrote is the projector's, even when no case type names it any more.
	 *
	 * @return void
	 */
	public function testATeamInTheBaselineIsManaged(): void {
		$team = self::team(group: 't1', caseTypes: ['ct1']);

		$wider = self::team(group: 't1', caseTypes: ['ct1', 'ct2']);

		$result = self::merge(desired: [self::ASSIGNEE], live: [self::ASSIGNEE, $wider], baseline: [self::ASSIGNEE, $team]);

		self::assertSame(expected: [self::ASSIGNEE], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testATeamInTheBaselineIsManaged()

	/**
	 * P3b: a case type taken off a team with a lagging baseline: the wider old rule does not survive.
	 *
	 * @return void
	 */
	public function testANarrowedTeamLosesItsWiderRule(): void {
		$result = self::merge(
			desired: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])],
			live: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1', 'ct-secret'])],
			baseline: [self::ASSIGNEE],
		);

		self::assertSame(expected: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])], actual: $result['block']['read']);
	}//end testANarrowedTeamLosesItsWiderRule()

	/**
	 * P4: without a baseline, a team-shaped rule for a team the projector does not manage is kept and reported.
	 *
	 * @return void
	 */
	public function testAnUnmanagedTeamShapedRuleIsKept(): void {
		$auditors = self::team(group: 'auditors', caseTypes: ['ct1']);

		$result = self::merge(desired: [self::ASSIGNEE], live: [self::ASSIGNEE, $auditors], baseline: null);

		self::assertSame(expected: [self::ASSIGNEE, $auditors], actual: $result['block']['read']);
		self::assertCount(expectedCount: 1, haystack: $result['conflicts']);
		self::assertStringStartsWith(prefix: 'read[auditors:', string: $result['conflicts'][0]);
	}//end testAnUnmanagedTeamShapedRuleIsKept()

	/**
	 * P5: without a baseline, a shipped rule an administrator removed is not brought back, and is reported.
	 *
	 * @return void
	 */
	public function testARemovedShippedRuleIsNotBroughtBackWithoutABaseline(): void {
		$result = self::merge(desired: [self::ASSIGNEE, self::KCC], live: [self::ASSIGNEE], baseline: null);

		self::assertSame(expected: [self::ASSIGNEE], actual: $result['block']['read']);
		self::assertCount(expectedCount: 1, haystack: $result['conflicts']);
	}//end testARemovedShippedRuleIsNotBroughtBackWithoutABaseline()

	/**
	 * A shipped rule an administrator removed stays removed with a baseline too, and is reported.
	 *
	 * @return void
	 */
	public function testARemovedShippedRuleStaysRemoved(): void {
		$result = self::merge(desired: [self::ASSIGNEE, self::KCC], live: [self::ASSIGNEE], baseline: [self::ASSIGNEE, self::KCC]);

		self::assertSame(expected: [self::ASSIGNEE], actual: $result['block']['read']);
		self::assertSame(expected: ['read[kcc:{"confidentiality":"openbaar"}]'], actual: $result['conflicts']);
	}//end testARemovedShippedRuleStaysRemoved()

	/**
	 * A shipped rule an administrator changed keeps the administrator's version.
	 *
	 * @return void
	 */
	public function testAChangedShippedRuleKeepsTheAdministratorsVersion(): void {
		$edited = ['group' => 'kcc', 'match' => ['confidentiality' => 'intern']];

		$result = self::merge(desired: [self::ASSIGNEE, self::KCC], live: [self::ASSIGNEE, $edited], baseline: [self::ASSIGNEE, self::KCC]);

		self::assertSame(expected: [self::ASSIGNEE, $edited], actual: $result['block']['read']);
		self::assertSame(expected: ['read[kcc:{"confidentiality":"openbaar"}]'], actual: $result['conflicts']);
	}//end testAChangedShippedRuleKeepsTheAdministratorsVersion()

	/**
	 * A shipped rule the file no longer carries goes; a new one is added.
	 *
	 * @return void
	 */
	public function testShippedRulesFollowTheFile(): void {
		$next = ['group' => 'kcc', 'match' => ['confidentiality' => 'intern']];

		$result = self::merge(desired: [self::ASSIGNEE, $next], live: [self::ASSIGNEE, self::KCC], baseline: [self::ASSIGNEE, self::KCC]);

		self::assertSame(expected: [self::ASSIGNEE, $next], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testShippedRulesFollowTheFile()

	/**
	 * The projector's own team rules update: a team taken off a case type loses its rule.
	 *
	 * @return void
	 */
	public function testTheProjectorsOwnRulesStillUpdate(): void {
		$before = [self::ASSIGNEE, self::team(group: 'vergunningen', caseTypes: ['ct-permit'])];
		$after = [self::ASSIGNEE, self::team(group: 'handhaving', caseTypes: ['ct-permit'])];

		$result = self::merge(desired: $after, live: $before, baseline: $before);

		self::assertSame(expected: $after, actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
		self::assertSame(expected: [], actual: $result['restored'], message: 'the projector replacing its own rule is no edit');
	}//end testTheProjectorsOwnRulesStillUpdate()

	/**
	 * R2: an administrator's narrowing of a managed team rule is undone, and reported.
	 *
	 * @return void
	 */
	public function testANarrowedTeamRuleIsRestoredAndReported(): void {
		$granted = self::team(group: 't1', caseTypes: ['ct1', 'ct2']);
		$narrowed = self::team(group: 't1', caseTypes: ['ct1']);

		$result = self::merge(desired: [self::ASSIGNEE, $granted], live: [self::ASSIGNEE, $narrowed], baseline: [self::ASSIGNEE, $granted]);

		self::assertSame(expected: [self::ASSIGNEE, $granted], actual: $result['block']['read']);
		self::assertSame(expected: ['read[t1:{"caseType":{"$in":["ct1"]}}]'], actual: $result['restored']);
	}//end testANarrowedTeamRuleIsRestoredAndReported()

	/**
	 * R1: an extra team-shaped rule for a managed team is removed, and reported.
	 *
	 * @return void
	 */
	public function testAnExtraManagedTeamRuleIsRemovedAndReported(): void {
		$granted = self::team(group: 't1', caseTypes: ['ct1']);
		$extra = self::team(group: 't1', caseTypes: ['ct9']);

		$result = self::merge(desired: [self::ASSIGNEE, $granted], live: [self::ASSIGNEE, $granted, $extra], baseline: [self::ASSIGNEE, $granted]);

		self::assertSame(expected: [self::ASSIGNEE, $granted], actual: $result['block']['read']);
		self::assertSame(expected: ['read[t1:{"caseType":{"$in":["ct9"]}}]'], actual: $result['restored']);
	}//end testAnExtraManagedTeamRuleIsRemovedAndReported()

	/**
	 * A managed team rule that is already what the case types grant is not reported, even when the baseline lags.
	 *
	 * @return void
	 */
	public function testAGrantedTeamRuleIsNotReported(): void {
		$granted = self::team(group: 't1', caseTypes: ['ct1']);

		$result = self::merge(desired: [self::ASSIGNEE, $granted], live: [self::ASSIGNEE, $granted], baseline: [self::ASSIGNEE]);

		self::assertSame(expected: [], actual: $result['restored']);
	}//end testAGrantedTeamRuleIsNotReported()

	/**
	 * Without a baseline, replacing a managed team rule is not reported: whose it was cannot be told.
	 *
	 * @return void
	 */
	public function testNothingIsReportedRestoredWithoutABaseline(): void {
		$result = self::merge(
			desired: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])],
			live: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1', 'ct2'])],
			baseline: null,
		);

		self::assertSame(expected: [], actual: $result['restored']);
	}//end testNothingIsReportedRestoredWithoutABaseline()

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
	}//end testNoLocalRuleIsWidened()

	/**
	 * P9: an administrator's edit of t1's rule for ct1 does not hold back t1's new rule.
	 *
	 * @return void
	 */
	public function testAnEditedTeamRuleDoesNotHoldBackTheTeamsNextRule(): void {
		$own = [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1'])];
		$edited = ['group' => 't1', 'match' => ['caseType' => ['$in' => ['ct1']]], 'x' => 1];

		$result = self::merge(
			desired: [self::ASSIGNEE, self::team(group: 't1', caseTypes: ['ct1', 'ct2'])],
			live: [self::ASSIGNEE, $edited],
			baseline: $own,
		);

		self::assertContains(needle: self::team(group: 't1', caseTypes: ['ct1', 'ct2']), haystack: $result['block']['read']);
		self::assertContains(needle: $edited, haystack: $result['block']['read']);
	}//end testAnEditedTeamRuleDoesNotHoldBackTheTeamsNextRule()

	/**
	 * A map is the projector's while the live value is what it wrote; otherwise the live value is kept and reported.
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
	 * P6: without a baseline, a map an administrator narrowed is kept and reported.
	 *
	 * @return void
	 */
	public function testANarrowedMapIsKeptWithoutABaseline(): void {
		$result = (new CaseAuthorizationMerge())->merge(
			desired: ['roles' => ['kcc' => ['read', 'update']]],
			live: ['roles' => ['kcc' => ['read']]],
			baseline: null,
		);

		self::assertSame(expected: ['roles' => ['kcc' => ['read']]], actual: $result['block']);
		self::assertSame(expected: ['roles'], actual: $result['conflicts']);
	}//end testANarrowedMapIsKeptWithoutABaseline()

	/**
	 * Without a baseline, an action missing from the live block entirely is written as derived.
	 *
	 * @return void
	 */
	public function testAMissingActionIsWrittenWithoutABaseline(): void {
		$result = (new CaseAuthorizationMerge())->merge(desired: ['read' => [self::ASSIGNEE, self::KCC]], live: [], baseline: null);

		self::assertSame(expected: ['read' => [self::ASSIGNEE, self::KCC]], actual: $result['block']);
	}//end testAMissingActionIsWrittenWithoutABaseline()

	/**
	 * Key order inside a rule does not make it a different rule.
	 *
	 * @return void
	 */
	public function testKeyOrderDoesNotMakeARuleLocal(): void {
		$reordered = ['match' => ['confidentiality' => 'openbaar'], 'group' => 'kcc'];

		$result = self::merge(desired: [self::KCC], live: [$reordered], baseline: [self::KCC]);

		self::assertSame(expected: [self::KCC], actual: $result['block']['read']);
		self::assertSame(expected: [], actual: $result['conflicts']);
	}//end testKeyOrderDoesNotMakeARuleLocal()

	/**
	 * A local rule the projector now derives too is written once.
	 *
	 * @return void
	 */
	public function testARuleOnBothSidesIsWrittenOnce(): void {
		$result = self::merge(desired: [self::ASSIGNEE, self::KCC], live: [self::ASSIGNEE, self::KCC], baseline: [self::ASSIGNEE]);

		self::assertSame(expected: [self::ASSIGNEE, self::KCC], actual: $result['block']['read']);
	}//end testARuleOnBothSidesIsWrittenOnce()
}//end class
