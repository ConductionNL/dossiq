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
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
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
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
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
	 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 */
	public function teamOf(IUser $user): ?array {
		$groupIds = $this->groupManager->getUserGroupIds($user);
		if ($groupIds === []) {
			return null;
		}

		return self::primaryTeam(roles: $this->roles(), userGroupIds: $groupIds);
	}//end teamOf()

	/**
	 * Pick the primary team from the organisation's roles and the user's groups.
	 *
	 * @param array<int, array<string, mixed>> $roles        The organisatieRol rows, each with `id`, `roleName`, `ncGroupId`, `parentRoleId`.
	 * @param array<int, string>               $userGroupIds The user's Nextcloud group ids.
	 *
	 * @return array{groupId: string, name: string}|null The deepest bound role's group, or null.
	 *
	 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 */
	public static function primaryTeam(array $roles, array $userGroupIds): ?array {
		$byId = [];
		foreach ($roles as $role) {
			$roleId = self::roleId(role: $role);
			if ($roleId !== '') {
				$byId[$roleId] = $role;
			}
		}

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
	 * A role's id, as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $role The role.
	 *
	 * @return string The id, or ''.
	 */
	private static function roleId(array $role): string {
		$self = $role['@self'] ?? [];
		$id = ($role['id'] ?? ($role['uuid'] ?? (is_array($self) === true ? ($self['id'] ?? '') : '')));

		return trim((string) $id);
	}//end roleId()

	/**
	 * The organisation's roles, read as the current user.
	 *
	 * @return array<int, array<string, mixed>> The roles, empty when they cannot be read.
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
				'Dossiq primary team: the organisation roles could not be read, so the user has no team',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}//end roles()
}//end class
