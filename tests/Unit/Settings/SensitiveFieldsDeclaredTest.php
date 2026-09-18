<?php

/**
 * Sensitive fields are declared, not guarded in PHP.
 *
 * The inventory below is the requirement's scope, and it is written out here
 * rather than derived. A test that re-derives the list from the same files it
 * checks passes on an empty list, and an empty list is exactly what a bad merge
 * leaves behind.
 *
 * 🔴 A SCHEMA VERSION THAT DID NOT MOVE MAKES THE WHOLE DECLARATION INERT.
 * OpenRegister fast-skips a schema whose version is the one it already holds,
 * so every rule below would be written into a file, committed, reviewed and
 * never imported, while every gate stayed green. The version assertions are
 * therefore part of this test and not a nicety.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every BSN and special-category field carries the extra-group read rule.
 *
 * @coversNothing
 */
class SensitiveFieldsDeclaredTest extends TestCase {

	/**
	 * The group the rule names.
	 */
	private const GROUP = 'dossiq-sensitive';

	/**
	 * The inventory task 1.1 recorded, schema to properties.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const INVENTORY = [
		'brpPerson' => ['citizenServiceNumber'],
		'wmoZaak' => ['bsn', 'supportRequest', 'householdsComposition', 'needsAssessmentId'],
		'jeugdwetZaak' => [
			'jeugdigeBsn',
			'supportRequest',
			'gezinsplanId',
			'supervisionOrderActive',
			'mdoConsultationIds',
		],
		'participatiewetZaak' => ['bsn', 'incomeAssessment', 'equityAssessment', 'householdsSituation'],
		'gezinsplan' => ['gezinsleden'],
		'indicatiestelling' => ['advisedSupport', 'investigationMinutes'],
		'mdoOverleg' => ['gedeeldeGegevens', 'minutes'],
		'toestemming' => ['grantedByBsn'],
	];

	/**
	 * The version each guarded schema moved to.
	 *
	 * @var array<string, string>
	 */
	private const VERSIONS = [
		'brpPerson' => '1.2.0',
		'wmoZaak' => '1.1.0',
		'jeugdwetZaak' => '1.1.0',
		'participatiewetZaak' => '1.1.0',
		'gezinsplan' => '1.1.0',
		'indicatiestelling' => '1.1.0',
		'mdoOverleg' => '1.1.0',
		'toestemming' => '1.1.0',
	];

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * The mock register, which ships the same schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $mock;

	/**
	 * Merge the register the way the app does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$root = __DIR__ . '/../../../lib/Settings';

		$base = json_decode((string)file_get_contents($root . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $root . '/register.d'
		);
		$this->merged = $merged;

		$this->mock = json_decode((string)file_get_contents($root . '/dossiq_mock_register.json'), true);
	}//end setUp()

	/**
	 * Every listed property is readable by the extra group only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#requirement-sensitive-fields-are-declared-behind-an-extra-group-req-sec-sf-1
	 */
	public function testEveryListedFieldCarriesTheExtraGroupRule(): void {
		$schemas = $this->merged['components']['schemas'];

		foreach (self::INVENTORY as $schema => $properties) {
			$this->assertArrayHasKey($schema, $schemas, $schema . ' must survive the fragment merge');

			foreach ($properties as $property) {
				$declared = ($schemas[$schema]['properties'][$property] ?? null);
				$this->assertIsArray($declared, $schema . '.' . $property . ' must exist');

				$read = (array)($declared['authorization']['read'] ?? []);
				$this->assertSame(
					[['group' => self::GROUP]],
					$read,
					$schema . '.' . $property . ' must be readable by ' . self::GROUP . ' only'
				);
			}
		}
	}//end testEveryListedFieldCarriesTheExtraGroupRule()

	/**
	 * The mock register carries the same rules, so a demo instance is not the
	 * one place the BSN is readable by everybody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#requirement-sensitive-fields-are-declared-behind-an-extra-group-req-sec-sf-1
	 */
	public function testTheMockRegisterCarriesTheSameRules(): void {
		$schemas = $this->mock['components']['schemas'];

		foreach (self::INVENTORY as $schema => $properties) {
			foreach ($properties as $property) {
				$read = (array)($schemas[$schema]['properties'][$property]['authorization']['read'] ?? []);
				$this->assertSame(
					[['group' => self::GROUP]],
					$read,
					'mock register ' . $schema . '.' . $property
				);
			}
		}
	}//end testTheMockRegisterCarriesTheSameRules()

	/**
	 * Each guarded schema's version moved, so OpenRegister imports the rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#requirement-sensitive-fields-are-declared-behind-an-extra-group-req-sec-sf-1
	 */
	public function testEveryGuardedSchemaMovedItsVersion(): void {
		$schemas = $this->merged['components']['schemas'];
		$mock = $this->mock['components']['schemas'];

		foreach (self::VERSIONS as $schema => $version) {
			$this->assertSame($version, (string)($schemas[$schema]['version'] ?? ''), $schema);
			$this->assertSame($version, (string)($mock[$schema]['version'] ?? ''), 'mock ' . $schema);
		}
	}//end testEveryGuardedSchemaMovedItsVersion()

	/**
	 * The group the rules name is a group this app creates.
	 *
	 * A group that does not exist and a group with no members are
	 * indistinguishable to `isInGroup()`, so a rule naming an unprovisioned
	 * group is a permanent denial nobody can lift from the Nextcloud UI.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#requirement-sensitive-fields-are-declared-behind-an-extra-group-req-sec-sf-1
	 */
	public function testTheGroupIsProvisioned(): void {
		$this->assertContains(self::GROUP, ProvisionAssignedGroups::ASSIGNED_GROUPS);
	}//end testTheGroupIsProvisioned()

	/**
	 * `CitizenLookupGuard` evaluates no declaration.
	 *
	 * MEASURED 2026-09-18: the class never had a field-level branch. It answered
	 * a single question, whether the caller may resolve a citizen identifier at
	 * all, which is the endpoint guard for PROC-IDOR-01 and not the field rule
	 * OpenRegister now enforces. Deleting it because this change "retires the
	 * guard" would have reopened an IDOR that returned a citizen's phone number
	 * and every previous call summary to any authenticated account.
	 *
	 * SHARPENED the same day, and this is why the assertion is not a method
	 * list any more. `citizen-lookup-is-guarded-and-recorded` adds
	 * `redactForCaller()`, which checks one group against one constant to take
	 * four keys OUT of a payload dossiq composed itself. Pinning the method
	 * list would have reddened on that, and reddened for the wrong reason: what
	 * this requirement forbids is a SECOND EVALUATOR of OpenRegister's
	 * declaration, which would eventually disagree with it. So the assertion is
	 * now that the class reads no schema, no property and no authorization
	 * block, which is the thing that would make it one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#requirement-sensitive-fields-are-declared-behind-an-extra-group-req-sec-sf-1
	 */
	public function testTheGuardDoesNotDecideAboutAField(): void {
		$reflection = new ReflectionClass(CitizenLookupGuard::class);
		$source = (string)file_get_contents((string)$reflection->getFileName());

		// Comments are stripped first. Every phrase below appears in this
		// class's own documentation, which names the declaration precisely so a
		// reader knows where the rule lives. Matching the prose would fail the
		// test for explaining itself.
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

		foreach (['schema', 'properties', 'authorization', 'getConfiguration'] as $needle) {
			$this->assertStringNotContainsStringIgnoringCase(
				$needle,
				$code,
				'CitizenLookupGuard must not read the declaration; a second evaluator of it '
				. 'eventually disagrees with OpenRegister and is fixed in whichever direction is easier'
			);
		}

		// And the redaction can only remove. `array_diff_key` over a constant
		// is the whole mechanism; a union or an assignment into the row would
		// be the shape that can grant.
		$this->assertStringContainsString('array_diff_key', $code);
	}//end testTheGuardDoesNotDecideAboutAField()
}//end class
