<?php

/**
 * The named municipal role set: shipped dormant, adopted in one act.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Ships a set of municipal roles nobody has to type, and lets an administrator
 * take it into use or put it back.
 *
 * 🔑 DORMANT, NOT GRANTED. Twenty-one roles appearing on an upgrade in an
 * instance that already has its own is a support call, and undoing it by hand
 * is worse than never shipping the set. So the roles arrive marked dormant,
 * they are offered, and adoption is one act with one undo.
 *
 * 🔑 THE UNDO STOPS THE MOMENT SOMEBODY IS IN A ROLE. A role that holds a grant
 * is load-bearing: removing it takes access away from whoever had it, silently,
 * and the administrator undoing an adoption they made this morning is not
 * asking for that. So the undo refuses and names the role that is in use.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class MunicipalRoleSetService {

	/**
	 * The app config key naming the role type schema.
	 */
	public const ROLE_TYPES = 'role_type_schema';

	/**
	 * The app config key naming the role schema, whose rows are the grants.
	 */
	public const ROLES = 'role_schema';

	/**
	 * The app config key naming the adoption record's schema.
	 */
	public const ADOPTIONS = 'starter_set_adoption_schema';

	/**
	 * The roles the set ships.
	 *
	 * Six, not twenty-one, and named for what a person does rather than for a
	 * department. D17 was answered for a broad market: `behandelaar` and
	 * `teamleider` read the same in an MKB back office as in a gemeente, and a
	 * set full of `gemeentesecretaris` would be dead weight for half the buyers.
	 *
	 * @var array<int, array{name: string, description: string, genericRole: string}>
	 */
	public const ROLES_SHIPPED = [
		[
			'name' => 'Behandelaar',
			'description' => 'Handles the case and answers for it.',
			'genericRole' => 'handler',
		],
		[
			'name' => 'Coordinator',
			'description' => 'Divides the work and watches the terms.',
			'genericRole' => 'coordinator',
		],
		[
			'name' => 'Teamleider',
			'description' => 'Carries the team and signs off what needs a second name.',
			'genericRole' => 'decision_maker',
		],
		[
			'name' => 'Intake',
			'description' => 'Takes work in and files it on the right case type.',
			'genericRole' => 'contact',
		],
		[
			'name' => 'Archief',
			'description' => 'Keeps and destroys records when their term is up.',
			'genericRole' => 'stakeholder',
		],
		[
			'name' => 'Lezer',
			'description' => 'Reads cases and changes nothing.',
			'genericRole' => 'stakeholder',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param StarterStore                $store   The OpenRegister seam.
	 * @param ShippedConfigurationService $shipped The provenance ledger.
	 * @param ShippedSets                 $sets    The sets dossiq ships, and their versions.
	 * @param IUserSession                $session Who is acting.
	 * @param LoggerInterface             $logger  Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly ShippedConfigurationService $shipped,
		private readonly ShippedSets $sets,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the set, dormant, once.
	 *
	 * Idempotent on the role name within the set, so a repair step that runs on
	 * every upgrade does not leave six Behandelaars.
	 *
	 * @return array{seeded: int, skipped: int}|null The tally, or null when the
	 *                                               store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function seed(): ?array {
		$existing = $this->shippedRoles();
		if ($existing === null) {
			return null;
		}

		$byName = [];
		foreach ($existing as $role) {
			$byName[(string)($role['name'] ?? '')] = true;
		}

		$tally = ['seeded' => 0, 'skipped' => 0];
		foreach (self::ROLES_SHIPPED as $role) {
			if (isset($byName[$role['name']]) === true) {
				$tally['skipped']++;
				continue;
			}

			$payload = ($role + ['shippedSet' => ShippedSets::MUNICIPAL_ROLES, 'dormant' => true]);
			$saved = $this->store->save(configKey: self::ROLE_TYPES, payload: $payload);
			if ($saved === null) {
				continue;
			}

			$tally['seeded']++;
			$this->shipped->stamp(
				set: ShippedSets::MUNICIPAL_ROLES,
				targetSchema: 'roleType',
				targetObject: $this->store->idOf(row: $saved),
				object: $saved,
			);
		}//end foreach

		return $tally;
	}//end seed()

	/**
	 * What the roles screen should show: the set, whether it is adopted, and
	 * whether anything has been granted against it.
	 *
	 * @return array<string, mixed>|null The offer, or null when unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function offer(): ?array {
		$roles = $this->shippedRoles();
		if ($roles === null) {
			return null;
		}

		$adoption = $this->openAdoption();

		return [
			'set' => ShippedSets::MUNICIPAL_ROLES,
			'setVersion' => $this->sets->versionOf(set: ShippedSets::MUNICIPAL_ROLES),
			'adopted' => ($adoption !== null),
			'adoptedBy' => (string)(($adoption['adoptedBy'] ?? '')),
			'adoptedAt' => (string)(($adoption['adoptedAt'] ?? '')),
			'rolesInUse' => $this->rolesHoldingGrants(roles: $roles),
			'roles' => array_map(
				fn (array $role): array => [
					'id' => $this->store->idOf(row: $role),
					'name' => (string)($role['name'] ?? ''),
					'description' => (string)($role['description'] ?? ''),
					'dormant' => (($role['dormant'] ?? false) === true),
				],
				$roles
			),
		];
	}//end offer()

	/**
	 * Take the set into use.
	 *
	 * @return array{ok: bool, reason: string, adopted: int} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function adopt(): array {
		$roles = $this->shippedRoles();
		if ($roles === null) {
			return ['ok' => false, 'reason' => 'unavailable', 'adopted' => 0];
		}

		if ($this->openAdoption() !== null) {
			return ['ok' => false, 'reason' => 'already_adopted', 'adopted' => 0];
		}

		$user = $this->session->getUser();
		$adopted = 0;
		foreach ($roles as $role) {
			$role['dormant'] = false;
			$saved = $this->store->save(
				configKey: self::ROLE_TYPES,
				payload: $role,
				id: $this->store->idOf(row: $role),
			);
			if ($saved !== null) {
				$adopted++;
			}
		}

		$record = $this->store->save(
			configKey: self::ADOPTIONS,
			payload: [
				'set' => ShippedSets::MUNICIPAL_ROLES,
				'setVersion' => $this->sets->versionOf(set: ShippedSets::MUNICIPAL_ROLES),
				'adoptedBy' => $this->actor(user: $user),
				'adoptedAt' => gmdate('c'),
			],
		);

		if ($record === null) {
			return ['ok' => false, 'reason' => 'write_failed', 'adopted' => $adopted];
		}

		$this->logger->info(
			'Dossiq starter: the municipal role set was adopted',
			['roles' => $adopted]
		);

		return ['ok' => true, 'reason' => '', 'adopted' => $adopted];
	}//end adopt()

	/**
	 * Put the set back to dormant, while nothing is granted against it.
	 *
	 * @return array{ok: bool, reason: string, roleInUse: string} What happened,
	 *         and which role stopped it when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function undoAdoption(): array {
		$roles = $this->shippedRoles();
		if ($roles === null) {
			return ['ok' => false, 'reason' => 'unavailable', 'roleInUse' => ''];
		}

		$adoption = $this->openAdoption();
		if ($adoption === null) {
			return ['ok' => false, 'reason' => 'not_adopted', 'roleInUse' => ''];
		}

		$inUse = $this->rolesHoldingGrants(roles: $roles);
		if ($inUse !== []) {
			return ['ok' => false, 'reason' => 'role_in_use', 'roleInUse' => $inUse[0]];
		}

		foreach ($roles as $role) {
			$role['dormant'] = true;
			$this->store->save(
				configKey: self::ROLE_TYPES,
				payload: $role,
				id: $this->store->idOf(row: $role),
			);
		}

		$adoption['reversedBy'] = $this->actor(user: $this->session->getUser());
		$adoption['reversedAt'] = gmdate('c');
		$this->store->save(
			configKey: self::ADOPTIONS,
			payload: $adoption,
			id: $this->store->idOf(row: $adoption),
		);

		return ['ok' => true, 'reason' => '', 'roleInUse' => ''];
	}//end undoAdoption()

	/**
	 * Who is acting, or '' when nobody is signed in.
	 *
	 * A repair step has no session, and the adoption record still has to be
	 * written: an empty actor is a fact worth recording, and refusing the write
	 * because nobody is signed in would leave the set half adopted.
	 *
	 * @param IUser|null $user The signed-in user, or null.
	 *
	 * @return string The user id, or ''.
	 */
	private function actor(?IUser $user): string {
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end actor()

	/**
	 * Every role type this set shipped.
	 *
	 * @return array<int, array<string, mixed>>|null The roles, or null when unreachable.
	 */
	private function shippedRoles(): ?array {
		return $this->store->rows(
			configKey: self::ROLE_TYPES,
			filters: ['shippedSet' => ShippedSets::MUNICIPAL_ROLES],
		);
	}//end shippedRoles()

	/**
	 * The adoption record that has not been reversed, or null.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private function openAdoption(): ?array {
		$records = $this->store->rows(
			configKey: self::ADOPTIONS,
			filters: ['set' => ShippedSets::MUNICIPAL_ROLES],
		);

		if ($records === null) {
			return null;
		}

		foreach ($records as $record) {
			if ((string)($record['reversedAt'] ?? '') === '') {
				return $record;
			}
		}

		return null;
	}//end openAdoption()

	/**
	 * The names of the shipped roles somebody is actually in.
	 *
	 * @param array<int, array<string, mixed>> $roles The shipped role types.
	 *
	 * @return array<int, string> The role names, in the order they were shipped.
	 */
	private function rolesHoldingGrants(array $roles): array {
		$inUse = [];
		foreach ($roles as $role) {
			$roleTypeId = $this->store->idOf(row: $role);
			if ($roleTypeId === '') {
				continue;
			}

			$grants = $this->store->rows(
				configKey: self::ROLES,
				filters: ['roleType' => $roleTypeId, '_limit' => 1],
			);

			// A null answer means "could not look", not "found none", and the
			// difference matters here: reporting no grants because the role
			// schema is unconfigured would let an undo strip access nobody
			// checked for.
			if ($grants === null || $grants !== []) {
				$inUse[] = (string)($role['name'] ?? '');
			}
		}

		return $inUse;
	}//end rolesHoldingGrants()
}//end class
