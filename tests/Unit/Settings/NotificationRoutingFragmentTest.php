<?php

/**
 * What the shipped notification routing declares, read off the merged register.
 *
 * THE DRIFT THIS CATCHES IS SILENT IN BOTH DIRECTIONS. A rule addressing a role
 * the case schema does not assign resolves to nobody and records
 * `recipient-unresolved`, which reads like a quiet team. A role assigned a group
 * the server does not have does the same, once per firing. And a
 * `caseType.notificationDomain` value no rule declares stores a preference the
 * dispatcher has no rule to match, which reads as a switch that does nothing.
 * None of the three fails anywhere else, so all three are asserted here, against
 * the MERGED configuration rather than the fragment, because the merge is what
 * OpenRegister actually imports.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The shipped routing: roles, groups and domains, all three swept.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
class NotificationRoutingFragmentTest extends TestCase {

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Merge the shipped register the way SettingsService does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$root = dirname(__DIR__, 3);
		$base = json_decode(
			(string)file_get_contents($root . '/lib/Settings/dossiq_register.json'),
			true
		);

		$merger = new RegisterFragmentMerger();
		[$merged] = $merger->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		$this->config = $merged;
	}//end setUp()

	/**
	 * Every role a rule addresses is assigned groups by its own schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testEveryAddressedRoleIsAssigned(): void {
		$addressed = 0;
		foreach ($this->schemas() as $name => $schema) {
			$assigned = array_keys((array)(($schema['authorization'] ?? [])['roles'] ?? []));

			foreach ($this->rulesOf(schema: $schema) as $key => $rule) {
				foreach ((array)($rule['recipients'] ?? []) as $recipient) {
					if (($recipient['kind'] ?? null) !== 'role') {
						continue;
					}

					$addressed++;
					$this->assertContains(
						needle: (string)($recipient['role'] ?? ''),
						haystack: $assigned,
						message: sprintf(
							'%s.%s addresses a role "%s" the schema does not assign, so it reaches nobody.',
							(string)$name,
							(string)$key,
							(string)($recipient['role'] ?? '')
						)
					);
				}
			}
		}

		$this->assertGreaterThan(
			expected: 0,
			actual: $addressed,
			message: 'No rule addresses a role, so this sweep proved nothing.'
		);
	}//end testEveryAddressedRoleIsAssigned()

	/**
	 * Every group a role assignment names is provisioned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testEveryAssignedGroupIsProvisioned(): void {
		$named = 0;
		foreach ($this->schemas() as $name => $schema) {
			$roles = (array)(($schema['authorization'] ?? [])['roles'] ?? []);
			foreach ($roles as $role => $groups) {
				foreach ((array)$groups as $group) {
					$named++;
					$this->assertContains(
						needle: (string)$group,
						haystack: ProvisionAssignedGroups::ASSIGNED_GROUPS,
						message: sprintf(
							'%s assigns role "%s" the group "%s", which nothing provisions: '
							. 'every firing records recipient-unresolved.',
							(string)$name,
							(string)$role,
							(string)$group
						)
					);
				}
			}
		}

		$this->assertGreaterThan(
			expected: 0,
			actual: $named,
			message: 'No role assignment names a group, so this sweep proved nothing.'
		);
	}//end testEveryAssignedGroupIsProvisioned()

	/**
	 * Every domain a rule declares is one a case type may choose, and back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testTheDomainsOfferedAreExactlyTheDomainsDeclared(): void {
		$declared = [];
		foreach ($this->schemas() as $schema) {
			foreach ($this->rulesOf(schema: $schema) as $rule) {
				$domain = ($rule['domain'] ?? null);
				if (is_string($domain) === true && $domain !== '') {
					$declared[$domain] = true;
				}
			}
		}

		$declared = array_keys($declared);
		sort($declared);

		$offered = (array)($this->caseTypeDomainProperty()['enum'] ?? []);
		sort($offered);

		$this->assertSame(
			expected: $declared,
			actual: $offered,
			message: 'The case type offers a domain no rule declares, or a rule declares one it cannot offer.'
		);

		$known = NotificationRouting::DOMAINS;
		sort($known);
		$this->assertSame(
			expected: $declared,
			actual: $known,
			message: 'NotificationRouting::DOMAINS has drifted from what the rules declare.'
		);
	}//end testTheDomainsOfferedAreExactlyTheDomainsDeclared()

	/**
	 * Every ROUTED rule carries Dutch wording for its body.
	 *
	 * 🔴 SCOPED TO THE ROUTED RULES ON PURPOSE, and it was not at first. Asserted
	 * over every rule in the register, this sweep turns any new rule any lane
	 * adds into a red for whoever merges next, which is how a feature branch
	 * becomes a debt sweep. A rule that declares a `domain` is one this change
	 * routes, so its wording is this change's to keep; a rule without one
	 * belongs to the lane that wrote it. `case.caseDeclaredMajor` (#2859) ships
	 * without a Dutch body and is reported rather than fixed here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testEveryRoutedRuleCarriesADutchBody(): void {
		$checked = 0;
		foreach ($this->schemas() as $name => $schema) {
			foreach ($this->rulesOf(schema: $schema) as $key => $rule) {
				if (($rule['domain'] ?? null) === null) {
					continue;
				}

				$checked++;
				$this->assertNotSame(
					expected: '',
					actual: trim((string)(($rule['message'] ?? [])['nl'] ?? '')),
					message: sprintf(
						'%s.%s is routed but has no Dutch body, so its notice falls back to a derived one.',
						(string)$name,
						(string)$key
					)
				);
			}
		}

		$this->assertGreaterThan(expected: 0, actual: $checked, message: 'No routed rules were swept.');
	}//end testEveryRoutedRuleCarriesADutchBody()

	/**
	 * The merged schemas.
	 *
	 * @return array<string, array<string, mixed>> The schemas.
	 */
	private function schemas(): array {
		return (array)(($this->config['components'] ?? [])['schemas'] ?? []);
	}//end schemas()

	/**
	 * One schema's notification rules.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<string, array<string, mixed>> The rules.
	 */
	private function rulesOf(array $schema): array {
		$rules = ($schema['x-openregister-notifications'] ?? null);
		if (is_array($rules) === false) {
			return [];
		}

		return array_filter($rules, static fn (mixed $r): bool => is_array($r));
	}//end rulesOf()

	/**
	 * The case type's notification domain property.
	 *
	 * @return array<string, mixed> The property.
	 */
	private function caseTypeDomainProperty(): array {
		$caseType = (array)($this->schemas()['caseType'] ?? []);

		return (array)(($caseType['properties'] ?? [])['notificationDomain'] ?? []);
	}//end caseTypeDomainProperty()
}//end class
