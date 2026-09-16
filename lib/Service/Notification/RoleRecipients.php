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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who holds a role on a case, answered by the platform's own resolver.
 *
 * dossiq used to walk `case.<role>` and `case.<role>Members` itself. That is
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
 * IT ANSWERS NULL RATHER THAN EMPTY when the platform cannot answer, because
 * "nobody holds this role" and "I could not ask" must not be the same value:
 * the caller falls back to its own lookup on the second, and reports no
 * recipients on the first.
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
	 * @param LoggerInterface $logger          Says why a resolve answered nothing.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
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

		try {
			$uids = $resolver->resolve(
				recipientsSpec: $spec,
				data: $case,
				object: null,
				context: [],
				roleGroups: $this->roleGroups()
			);
		} catch (Throwable $e) {
			$this->logger->info('Dossiq notifications: the role was not resolved by the platform: ' . $e->getMessage());
			return null;
		}

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

		try {
			$schema = $mapper->find($slug);
		} catch (Throwable $e) {
			$this->logger->info('Dossiq notifications: the case schema was not read: ' . $e->getMessage());
			return [];
		}

		if (is_object($schema) === false || method_exists($schema, 'getAuthorization') === false) {
			return [];
		}

		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false) {
			return [];
		}

		$roles = ($authorization['roles'] ?? null);
		if (is_array($roles) === false) {
			return [];
		}

		$out = [];
		foreach ($roles as $role => $groups) {
			if (is_string($role) === false || is_array($groups) === false) {
				continue;
			}

			$out[$role] = array_values(
				array_filter($groups, static fn (mixed $g): bool => (is_string($g) === true && $g !== ''))
			);
		}

		return $out;
	}//end roleGroups()
}//end class
