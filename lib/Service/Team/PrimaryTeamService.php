<?php

/**
 * Dossiq PrimaryTeamService
 *
 * The current user's primary team: the most specific organisation role whose
 * Nextcloud group (`organisatieRol.ncGroupId`) the user is a member of. A
 * department role and the team role under it can both bind a group the user
 * is in; the team is the one deepest in the `parentRoleId` tree, so Pieter in
 * "Gemeente Zuiddrecht" and in "Informatie en Woo" has Informatie en Woo as
 * his team. Two equally deep roles are ordered by role name, so the answer
 * does not change between requests. A user whose groups no role binds has no
 * team; the user's other groups are never offered instead.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Team
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers which Nextcloud group is a user's primary team.
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */
class PrimaryTeamService {
	use SearchesObjects;

	/**
	 * The most roles read to place a user; an organisation's role tree is far smaller.
	 */
	private const ROLE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reaches the register, the schema and OpenRegister.
	 * @param IGroupManager   $groupManager    The user's Nextcloud groups.
	 * @param LoggerInterface $logger          Says why a team could not be read.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The user's primary team.
	 *
	 * @param IUser $user The user.
	 *
	 * @return array{groupId: string, name: string}|null The team's group id and role name, or null for no team.
	 *
	 * @throws Throwable When the organisation roles cannot be read.
	 *
	 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 */
	public function teamOf(IUser $user): ?array {
		$groupIds = $this->groupManager->getUserGroupIds($user);
		if ($groupIds === []) {
			return null;
		}

		return self::primaryTeam(roles: $this->roles(), userGroupIds: $groupIds);
	}//end teamOf()

	/**
	 * Every team the user belongs to, the primary team first.
	 *
	 * @param IUser $user The user.
	 *
	 * @return array<int, array{groupId: string, name: string}> The teams; empty for a user in none.
	 *
	 * @throws Throwable When the organisation roles cannot be read.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public function teamsOf(IUser $user): array {
		$groupIds = $this->groupManager->getUserGroupIds($user);
		if ($groupIds === []) {
			return [];
		}

		return self::teams(roles: $this->roles(), userGroupIds: $groupIds);
	}//end teamsOf()

	/**
	 * The user's teams: each role that binds one of the user's groups and is no
	 * ancestor of another such role (the municipality above a team is not a
	 * team of its own), ordered as the primary team is chosen: deepest first,
	 * then by name. The admin group is never a team.
	 *
	 * @param array<int, array<string, mixed>> $roles        The organisatieRol rows.
	 * @param array<int, string>               $userGroupIds The user's Nextcloud group ids.
	 *
	 * @return array<int, array{groupId: string, name: string}> The teams.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public static function teams(array $roles, array $userGroupIds): array {
		$byId = self::rolesById(roles: $roles);
		$bound = self::boundRoles(roles: $roles, userGroupIds: $userGroupIds);
		$ancestors = self::ancestorSet(bound: $bound, byId: $byId);

		$teams = [];
		$seen  = [];
		foreach ($bound as $role) {
			$group = trim((string)$role['ncGroupId']);
			if (isset($ancestors[self::roleId(role: $role)]) === true || isset($seen[$group]) === true) {
				continue;
			}

			$seen[$group] = true;
			$teams[] = [
				'groupId' => $group,
				'name'    => trim((string)($role['roleName'] ?? '')),
				'depth'   => self::depth(role: $role, byId: $byId),
			];
		}

		usort($teams, static fn (array $a, array $b): int => self::order(first: $a, second: $b));

		return array_map(
			static fn (array $team): array => ['groupId' => $team['groupId'], 'name' => $team['name']],
			$teams
		);
	}//end teams()

	/**
	 * The roles by id; a role without one is left out.
	 *
	 * @param array<int, array<string, mixed>> $roles The organisatieRol rows.
	 *
	 * @return array<string, array<string, mixed>> The roles keyed by id.
	 */
	private static function rolesById(array $roles): array {
		$byId = [];
		foreach ($roles as $role) {
			$roleId = self::roleId(role: $role);
			if ($roleId !== '') {
				$byId[$roleId] = $role;
			}
		}

		return $byId;
	}//end rolesById()

	/**
	 * The roles that bind one of the user's groups; the admin group binds none.
	 *
	 * @param array<int, array<string, mixed>> $roles        The organisatieRol rows.
	 * @param array<int, string>               $userGroupIds The user's Nextcloud group ids.
	 *
	 * @return array<int, array<string, mixed>> The bound roles.
	 */
	private static function boundRoles(array $roles, array $userGroupIds): array {
		return array_values(
			array_filter(
				$roles,
				static function (array $role) use ($userGroupIds): bool {
					$group = trim((string)($role['ncGroupId'] ?? ''));
					return $group !== '' && $group !== 'admin' && in_array($group, $userGroupIds, true) === true;
				}
			)
		);
	}//end boundRoles()

	/**
	 * The ids of every ancestor of a bound role.
	 *
	 * @param array<int, array<string, mixed>>    $bound The bound roles.
	 * @param array<string, array<string, mixed>> $byId  Every role by id.
	 *
	 * @return array<string, true> The ancestor ids.
	 */
	private static function ancestorSet(array $bound, array $byId): array {
		$ancestors = [];
		foreach ($bound as $role) {
			foreach (self::ancestorIds(role: $role, byId: $byId) as $ancestorId) {
				$ancestors[$ancestorId] = true;
			}
		}

		return $ancestors;
	}//end ancestorSet()

	/**
	 * The order of two teams as the primary team is chosen.
	 *
	 * @param array<string, mixed> $first  A team.
	 * @param array<string, mixed> $second Another team.
	 *
	 * @return int Negative when the first comes before the second.
	 */
	private static function order(array $first, array $second): int {
		if (self::isBefore(candidate: $first, best: $second) === true) {
			return -1;
		}

		if (self::isBefore(candidate: $second, best: $first) === true) {
			return 1;
		}

		return 0;
	}//end order()

	/**
	 * Pick the primary team from the organisation's roles and the user's groups.
	 *
	 * @param array<int, array<string, mixed>> $roles        The organisatieRol rows, each with `id`, `roleName`, `ncGroupId`, `parentRoleId`.
	 * @param array<int, string>               $userGroupIds The user's Nextcloud group ids.
	 *
	 * @return array{groupId: string, name: string}|null The deepest bound role's group, or null.
	 *
	 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 */
	public static function primaryTeam(array $roles, array $userGroupIds): ?array {
		$byId = self::rolesById(roles: $roles);

		$best = null;
		foreach ($roles as $role) {
			$group = trim((string)($role['ncGroupId'] ?? ''));
			if ($group === '' || in_array($group, $userGroupIds, true) === false) {
				continue;
			}

			$candidate = [
				'groupId' => $group,
				'name' => trim((string)($role['roleName'] ?? '')),
				'depth'   => self::depth(role: $role, byId: $byId),
			];
			if ($best === null || self::isBefore(candidate: $candidate, best: $best) === true) {
				$best = $candidate;
			}
		}

		if ($best === null) {
			return null;
		}

		return ['groupId' => $best['groupId'], 'name' => $best['name']];
	}//end primaryTeam()

	/**
	 * Whether a candidate team wins over the best so far: deeper, then by name.
	 *
	 * @param array{groupId: string, name: string, depth: int} $candidate The candidate.
	 * @param array{groupId: string, name: string, depth: int} $best      The best so far.
	 *
	 * @return bool True when the candidate wins.
	 */
	private static function isBefore(array $candidate, array $best): bool {
		if ($candidate['depth'] !== $best['depth']) {
			return $candidate['depth'] > $best['depth'];
		}

		return strcmp($candidate['name'], $best['name']) < 0;
	}//end isBefore()

	/**
	 * How many parents a role has, stopping at a cycle or a missing parent.
	 *
	 * @param array<string, mixed>                $role The role.
	 * @param array<string, array<string, mixed>> $byId Every role by id.
	 *
	 * @return int The depth, 0 for a root.
	 */
	private static function depth(array $role, array $byId): int {
		$depth = 0;
		$seen = [];
		$parent = trim((string)($role['parentRoleId'] ?? ''));
		while ($parent !== '' && isset($byId[$parent]) === true && isset($seen[$parent]) === false) {
			$seen[$parent] = true;
			$depth++;
			$parent = trim((string)($byId[$parent]['parentRoleId'] ?? ''));
		}

		return $depth;
	}//end depth()

	/**
	 * The ids of a role's parents, nearest first, stopping at a cycle or a missing parent.
	 *
	 * @param array<string, mixed>                $role The role.
	 * @param array<string, array<string, mixed>> $byId Every role by id.
	 *
	 * @return array<int, string> The parent role ids.
	 */
	private static function ancestorIds(array $role, array $byId): array {
		$ids = [];
		$parent = trim((string)($role['parentRoleId'] ?? ''));
		while ($parent !== '' && isset($byId[$parent]) === true && in_array($parent, $ids, true) === false) {
			$ids[] = $parent;
			$parent = trim((string)($byId[$parent]['parentRoleId'] ?? ''));
		}

		return $ids;
	}//end ancestorIds()

	/**
	 * A role's id, as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $role The role.
	 *
	 * @return string The id, or ''.
	 */
	private static function roleId(array $role): string {
		$id = ($role['id'] ?? ($role['uuid'] ?? ''));
		$self = ($role['@self'] ?? null);
		if ($id === '' && is_array($self) === true) {
			$id = ($self['id'] ?? '');
		}

		return trim((string) $id);
	}//end roleId()

	/**
	 * The organisation's roles, read as the current user.
	 *
	 * @return array<int, array<string, mixed>> The roles; empty when the store or its settings are absent.
	 *
	 * @throws Throwable When the store is there but the roles cannot be read.
	 */
	private function roles(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'organisatie_rol_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::ROLE_LIMIT],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq primary team: the organisation roles could not be read',
				['exception' => $e->getMessage()]
			);
			throw $e;
		}
	}//end roles()
}//end class
