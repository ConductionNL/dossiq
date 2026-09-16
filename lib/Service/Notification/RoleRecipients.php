<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use OCA\Dossiq\Service\SettingsService;

/**
 * Who holds a role on a case, answered by the platform's own resolver.
 *
 * This app used to walk `case.<role>` and `case.<role>Members` itself. That is
 * `{kind: field}` in OpenRegister's recipients dialect, and OpenRegister now
 * also resolves `{kind: role}` against the schema's `authorization.roles`. One
 * resolver deciding who is told is the point (ADR-012): two of them is how a
 * notification rule and an automatic action come to disagree about the same
 * team.
 *
 * WHAT THE DELEGATION ADDS. The field lookup only ever found somebody the case
 * itself names. Addressing the role as well reaches the team through the
 * schema's assignment, so a `notifyRole` action naming `behandelaar` reaches
 * the behandelaars even on a case that carries no behandelaar field.
 *
 * IT ANSWERS NULL RATHER THAN EMPTY when this instance has no such platform,
 * because "nobody holds this role" and "there is nothing to ask" must not be
 * the same value: the caller falls back to its own lookup on the second, and
 * reports no recipients on the first.
 *
 * A resolver that THROWS is neither. It propagates, because falling back on a
 * fault would reach fewer people than a working resolver with nothing saying
 * so, and a notification that quietly reached half the team is the failure this
 * whole change exists to remove.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RoleRecipients {

	/**
	 * OpenRegister's recipient resolver, named as a string so this app never
	 * imports a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const RESOLVER = 'OCA\\OpenRegister\\Service\\Notification\\NotificationRecipientResolver';

	/**
	 * OpenRegister's schema mapper, for the role assignment.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access (ADR-083).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Everyone who holds this role on this case.
	 *
	 * @param string               $roleSlug The role, as the action names it.
	 * @param array<string, mixed> $case     The case object.
	 *
	 * @return array<int, string>|null The user ids, or null when the platform could not answer.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function forRole(string $roleSlug, array $case): ?array {
		if ($roleSlug === '') {
			return null;
		}

		$resolver = $this->settingsService->getOpenRegisterClass(class: self::RESOLVER);
		if (is_object($resolver) === false || method_exists($resolver, 'resolve') === false) {
			return null;
		}

		$spec = [
			['kind' => 'field', 'field' => $roleSlug],
			['kind' => 'field', 'field' => ($roleSlug . 'Members')],
			['kind' => 'role', 'role' => $roleSlug],
		];

		// A resolver that THROWS is not a reason to fall back. Catching it here
		// and answering null would send the caller to its own weaker lookup, so
		// a broken resolver would quietly reach fewer people than a working one
		// and nothing would say so (ADR-005, fail closed and say so). The only
		// null this method returns is the one decided above, before any call:
		// the platform is not on this instance, which is a fact, not a fault.
		$uids = $resolver->resolve(
			recipientsSpec: $spec,
			data: $case,
			object: null,
			context: [],
			roleGroups: $this->roleGroups()
		);

		if (is_array($uids) === false) {
			return null;
		}

		$out = [];
		foreach ($uids as $uid) {
			if (is_string($uid) === true && $uid !== '') {
				$out[] = $uid;
			}
		}

		return array_values(array_unique($out));
	}//end forRole()

	/**
	 * The case schema's role-to-groups assignment.
	 *
	 * An empty map is not a failure: it says this schema assigns no roles, and
	 * the field kinds in the spec still answer.
	 *
	 * @return array<string, array<int, string>> The assignment.
	 */
	private function roleGroups(): array {
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::SCHEMA_MAPPER);
		$slug = $this->settingsService->getConfigValue('case_schema');
		if (is_object($mapper) === false || $slug === '') {
			return [];
		}

		// A schema read that THROWS propagates, for the same reason the resolve
		// above does: answering "this schema assigns no roles" when the truth is
		// "I could not read it" turns a fault into a rule that quietly reaches
		// fewer people.
		$schema = $mapper->find($slug);
		if (is_object($schema) === false || method_exists($schema, 'getAuthorization') === false) {
			return [];
		}

		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false) {
			return [];
		}

		return $this->normaliseRoles(roles: ($authorization['roles'] ?? null));
	}//end roleGroups()

	/**
	 * One role-to-groups map with every unusable entry dropped.
	 *
	 * @param mixed $roles The assignment as the schema carries it.
	 *
	 * @return array<string, array<int, string>> The assignment.
	 */
	private function normaliseRoles(mixed $roles): array {
		if (is_array($roles) === false) {
			return [];
		}

		$out = [];
		foreach ($roles as $role => $groups) {
			if (is_string($role) === false || is_array($groups) === false) {
				continue;
			}

			$named = [];
			foreach ($groups as $group) {
				if (is_string($group) === true && $group !== '') {
					$named[] = $group;
				}
			}

			$out[$role] = $named;
		}

		return $out;
	}//end normaliseRoles()
}//end class
