<?php

/**
 * The bezwaar-beroep package declares its links as configuration.
 *
 * Reads the shipped package files and checks that the relation roles, the
 * marker rule and the cascades are accepted by the generic classes that act
 * on them (linked-case-with-a-role task 2.1). The marker rule is compared with
 * the procedure listener it replaces on a matrix of beroep and bezwaar states,
 * so the move in task 2.2 can rely on the two deciding the same.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/linked-case-with-a-role/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Listener\BeroepEscalationListener;
use OCA\Dossiq\Service\CaseDefinition\PackageSettingRules;
use OCA\Dossiq\Service\Relation\LinkMarkerRule;
use OCA\Dossiq\Service\Relation\LinkMarkerRuleReader;
use OCA\Dossiq\Service\Relation\RoleLinkDefinition;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for the bezwaar-beroep link configuration.
 */
class BezwaarPackageLinkConfigTest extends TestCase {

	/**
	 * The package directory.
	 *
	 * @var string
	 */
	private const PACKAGE = __DIR__ . '/../../../lib/Settings/packages/bezwaar-beroep/';

	/**
	 * A decoded package file.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string, mixed>
	 */
	private static function file(string $name): array {
		return (array)json_decode((string)file_get_contents(self::PACKAGE . $name), true, 512, JSON_THROW_ON_ERROR);
	}//end file()

	/**
	 * A case type of the package by identifier.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseType(string $identifier): array {
		foreach (self::file(name: 'case-type.json')['caseType'] as $caseType) {
			if ($caseType['identifier'] === $identifier) {
				return $caseType;
			}
		}

		self::fail("No case type $identifier in the package.");
	}//end caseType()

	/**
	 * The package ships its settings part, and every setting is a scalar the installer can store.
	 *
	 * @return void
	 */
	public function testTheSettingsPartIsShippedAndValid(): void {
		$this->assertContains('settings', self::file(name: 'package.json')['parts']);
		$this->assertSame([], (new PackageSettingRules())->errors(settings: self::file(name: 'settings.json')));
	}

	/**
	 * A bezwaar contests a decision: the role writes a follow-up relation to the
	 * decision's case and opens the objection record with who registered it, as
	 * BezwaarCreationHook does.
	 *
	 * @return void
	 */
	public function testTheBezwaarContestsADecision(): void {
		$roles = self::caseType(identifier: 'bezwaar')['relations'];
		$this->assertCount(1, $roles);
		$role = new RoleLinkDefinition(config: $roles[0]);

		$this->assertSame('contests', $role->role);
		$this->assertSame(RoleLinkDefinition::TARGET_OBJECT, $role->target);
		$this->assertSame('decision_schema', $role->targetSchema);
		$this->assertSame('case', $role->targetCaseField);
		$this->assertSame('vervolg', $role->natureRelationship);
		$this->assertTrue($role->requiredAtIntake);
		$this->assertSame(['objection_schema', 'case', 'contestedDecision', 'registeredBy'], [$role->recordSchema, $role->recordCaseField, $role->recordTargetField, $role->recordActorField]);
	}

	/**
	 * A beroep appeals a decision on objection, recorded on the beroep's sourceObjection.
	 *
	 * @return void
	 */
	public function testTheBeroepAppealsTheObjection(): void {
		$role = new RoleLinkDefinition(config: self::caseType(identifier: 'beroep')['relations'][0]);

		$this->assertSame('appeals', $role->role);
		$this->assertSame('bezwaar_schema', $role->targetSchema);
		$this->assertFalse($role->requiredAtIntake);
		$this->assertSame(['beroep_schema', 'case', 'sourceObjection'], [$role->recordSchema, $role->recordCaseField, $role->recordTargetField]);
		$this->assertNull($role->recordActorField);
	}

	/**
	 * The one marker rule: dwingendStatus on the bezwaar a beroep points at.
	 *
	 * @return LinkMarkerRule
	 */
	private static function markerRule(): LinkMarkerRule {
		$rules = (new LinkMarkerRuleReader())->fromJson(json: self::file(name: 'settings.json')['link_marker_rules']);
		self::assertCount(1, $rules);

		return $rules[0];
	}//end markerRule()

	/**
	 * The rule reads the beroep and writes the bezwaar.
	 *
	 * @return void
	 */
	public function testTheMarkerRuleNamesTheBeroepAndTheBezwaar(): void {
		$rule = self::markerRule();

		$this->assertSame(['beroep_schema', 'sourceObjection', 'bezwaar_schema', 'dwingendStatus'], [$rule->source, $rule->field, $rule->target, $rule->marker]);
	}

	/**
	 * Beroep and bezwaar states the two implementations are compared on.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function states(): array {
		$beroep = ['sourceObjection' => 'bz-1', 'contestedDecision' => 'd-1', 'appellantFilingDate' => '2026-03-01', 'filingDeadline' => '2026-03-10'];
		$handled = ['status' => 'Handled'];

		return [
			'in time, bezwaar handled' => [$beroep, $handled],
			'filed on the deadline' => [['appellantFilingDate' => '2026-03-10'] + $beroep, $handled],
			'filed late' => [['appellantFilingDate' => '2026-03-11'] + $beroep, $handled],
			'no deadline yet' => [['filingDeadline' => ''] + $beroep, $handled],
			'no filing date' => [['appellantFilingDate' => ''] + $beroep, $handled],
			'bezwaar still open' => [$beroep, ['status' => 'In behandeling']],
			'bezwaar inadmissible' => [$beroep, ['status' => 'Inadmissible']],
			'bezwaar withdrawn' => [$beroep, ['status' => 'Withdrawn']],
			'no contested decision' => [['contestedDecision' => ''] + $beroep, $handled],
			'judgment pending' => [['judgmentOutcome' => ''] + $beroep, $handled],
			'judgment upheld' => [['judgmentOutcome' => 'upheld'] + $beroep, $handled],
			'settled' => [['judgmentOutcome' => 'schikking'] + $beroep, $handled],
			'other outcome' => [['judgmentOutcome' => 'partially'] + $beroep, $handled],
		];
	}

	/**
	 * The configured rule decides as the listener it replaces.
	 *
	 * @param array<string, mixed> $beroep  The beroep.
	 * @param array<string, mixed> $bezwaar The bezwaar it points at.
	 *
	 * @return void
	 */
	#[DataProvider('states')]
	public function testTheMarkerRuleDecidesAsTheListener(array $beroep, array $bezwaar): void {
		$listener = new BeroepEscalationListener($this->createMock(SettingsService::class), new NullLogger());
		$legacy = (new ReflectionMethod($listener, 'shouldFlagDwingend'))->invoke($listener, $beroep, $bezwaar);

		$this->assertSame($legacy, self::markerRule()->holds(source: $beroep, target: $bezwaar));
	}

	/**
	 * Each follow-up a beroep can record is a cascade on the beroep that sets it.
	 *
	 * @return void
	 */
	public function testTheBeroepFollowUpsAreCascades(): void {
		$cascades = json_decode(self::file(name: 'settings.json')['link_cascades'], true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(['reopen_objection', 'new_primary_decision', 'none'], array_keys($cascades));
		foreach ($cascades as $name => $cascade) {
			$this->assertSame('beroep_schema', $cascade['target']);
			$this->assertSame([['set' => ['cascadeAction' => $name]]], $cascade['steps']);
		}
	}
}
