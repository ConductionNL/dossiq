<?php

/**
 * Move every case from an organisation role to the Nextcloud group that role names.
 *
 * one-team-model: a case's team is a Nextcloud group id. Until it shipped,
 * `case.assignedGroup` was a `$ref` to `organisatieRol`, so a case picked on
 * the case page holds a role uuid while a case that was handed over already
 * holds a group id. This service turns the first kind into the second.
 *
 * 🔴 IT NEVER GUESSES. Today nothing maps a role to a group: the role carries
 * `roleName`, `department`, `team` (free text) and `publicName`, and no code
 * joins any of them to a group. So the only mapping is the one an
 * administrator states, `organisatieRol.ncGroupId`. A role without it, or
 * whose group does not exist, leaves the case as it is and the case is
 * reported. Groups whose id or display name match the role are listed as a
 * HINT for the administrator and never applied: a wrong guess moves a case to
 * a team that never sees it, which is worse than a uuid somebody can find.
 *
 * Idempotent: a converted case holds a group id afterwards, and a case that
 * already holds an existing group id is left alone.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Team
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
 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Team;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Converts stored organisation role references on cases into Nextcloud group ids.
 *
 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
 */
class CaseTeamMigration {

	use SearchesObjects;

	/**
	 * The role has no `ncGroupId`.
	 *
	 * @var string
	 */
	public const REASON_NO_GROUP = 'role-has-no-group';

	/**
	 * The role names a group that does not exist.
	 *
	 * @var string
	 */
	public const REASON_GROUP_MISSING = 'role-group-missing';

	/**
	 * The value is neither a group nor a role.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown-team';

	/**
	 * Rows read per page.
	 *
	 * @var int
	 */
	public const PAGE = 200;

	/**
	 * The case field holding the team.
	 *
	 * @var string
	 */
	private const FIELD = 'assignedGroup';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the app config.
	 * @param IGroupManager   $groups          Nextcloud's groups, the one authority on a team.
	 * @param LoggerInterface $logger          Logger.
	 *
	 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Walk every case and convert what can be converted.
	 *
	 * @param bool $apply False reports what would happen and writes nothing.
	 *
	 * @return array{
	 *     ran: bool,
	 *     skippedBecause: string,
	 *     converted: int,
	 *     alreadyGroup: int,
	 *     failed: int,
	 *     unmapped: list<array{case: string, title: string, value: string, reason: string, roleName: string, hints: list<string>}>
	 * } The report. `ran` is false when OpenRegister or the case schema is not
	 *   configured, and `skippedBecause` says which.
	 *
	 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function run(bool $apply): array {
		$report = [
			'ran' => false,
			'skippedBecause' => '',
			'converted' => 0,
			'alreadyGroup' => 0,
			'failed' => 0,
			'unmapped' => [],
		];

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$report['skippedBecause'] = 'OpenRegister is not available';
			return $report;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $caseSchema === '') {
			$report['skippedBecause'] = 'the case schema is not configured';
			return $report;
		}

		$roleSchema = $this->settingsService->getConfigValue(key: 'organisatie_rol_schema');

		$result = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $caseSchema, $roleSchema, $apply, $report): array {
				$report['ran'] = true;
				$roles = $this->roles(objectService: $objectService, register: $register, roleSchema: $roleSchema);

				foreach ($this->cases(objectService: $objectService, register: $register, caseSchema: $caseSchema) as $case) {
					$report = $this->sortOne(
						case: $case,
						roles: $roles,
						apply: $apply,
						report: $report,
						write: function (string $caseId, string $group) use ($objectService, $register, $caseSchema): void {
							$this->patchObjectAsArray(
								objectService: $objectService,
								register: $register,
								schema: $caseSchema,
								id: $caseId,
								changes: [self::FIELD => $group],
							);
						},
					);
				}

				return $report;
			}
		);

		if (is_array($result) === false) {
			return $report;
		}

		return $result;
	}//end run()

	/**
	 * Decide what one case is, and convert it when it can be.
	 *
	 * @param array<string, mixed>                $case   The stored case.
	 * @param array<string, array<string, mixed>> $roles  The organisation roles by id.
	 * @param bool                                $apply  Whether to write.
	 * @param array<string, mixed>                $report The report so far.
	 * @param callable(string, string): void      $write  Writes a group onto a case.
	 *
	 * @return array<string, mixed> The report with this case counted.
	 *
	 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	private function sortOne(array $case, array $roles, bool $apply, array $report, callable $write): array {
		$value = $this->referenceId(value: ($case[self::FIELD] ?? ''));
		if ($value === '') {
			return $report;
		}

		if ($this->groups->groupExists($value) === true) {
			$report['alreadyGroup'] += 1;
			return $report;
		}

		$caseId = $this->referenceId(value: ($case['id'] ?? ($case['uuid'] ?? ($case['@self'] ?? ''))));
		$role = ($roles[$value] ?? null);
		$group = '';
		$reason = self::REASON_UNKNOWN;
		if ($role !== null) {
			$group = trim((string)($role['ncGroupId'] ?? ''));
			$reason = self::REASON_NO_GROUP;
			if ($group !== '') {
				$reason = self::REASON_GROUP_MISSING;
			}
		}

		if ($group !== '' && $this->groups->groupExists($group) === true) {
			if ($apply === false) {
				$report['converted'] += 1;
				return $report;
			}

			try {
				$write($caseId, $group);
				$report['converted'] += 1;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq team migration: case {case} could not be moved to group {group}',
					['case' => $caseId, 'group' => $group, 'exception' => $e->getMessage()],
				);
				$report['failed'] += 1;
			}

			return $report;
		}

		$report['unmapped'][] = [
			'case' => $caseId,
			'title' => trim((string)($case['title'] ?? '')),
			'value' => $value,
			'reason' => $reason,
			'roleName' => trim((string)($role['roleName'] ?? '')),
			'hints' => $this->hints(role: ($role ?? [])),
		];

		return $report;
	}//end sortOne()

	/**
	 * Groups that look like the role, for a person to confirm. Never applied.
	 *
	 * @param array<string, mixed> $role The organisation role, or [] when the value named none.
	 *
	 * @return list<string> Group ids, without duplicates.
	 */
	private function hints(array $role): array {
		$hints = [];
		foreach (['team', 'roleName'] as $field) {
			$candidate = trim((string)($role[$field] ?? ''));
			if ($candidate !== '' && $this->groups->groupExists($candidate) === true) {
				$hints[] = $candidate;
			}
		}

		$name = trim((string)($role['roleName'] ?? ''));
		if ($name !== '') {
			foreach ($this->groups->search($name) as $group) {
				if (strcasecmp($group->getDisplayName(), $name) === 0) {
					$hints[] = $group->getGID();
				}
			}
		}

		return array_values(array_unique($hints));
	}//end hints()

	/**
	 * Every organisation role, by id.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $roleSchema    The organisatieRol schema, or '' when not configured.
	 *
	 * @return array<string, array<string, mixed>> The roles by id.
	 */
	private function roles(object $objectService, string $register, string $roleSchema): array {
		if ($roleSchema === '') {
			return [];
		}

		$roles = [];
		foreach ($this->pages(objectService: $objectService, register: $register, schema: $roleSchema) as $row) {
			$id = $this->referenceId(value: ($row['id'] ?? ($row['uuid'] ?? ($row['@self'] ?? ''))));
			if ($id !== '') {
				$roles[$id] = $row;
			}
		}

		return $roles;
	}//end roles()

	/**
	 * Every case, page by page.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $caseSchema    The case schema.
	 *
	 * @return iterable<array<string, mixed>> The cases.
	 */
	private function cases(object $objectService, string $register, string $caseSchema): iterable {
		return $this->pages(objectService: $objectService, register: $register, schema: $caseSchema);
	}//end cases()

	/**
	 * Read a whole schema in pages, regardless of the caller.
	 *
	 * Stops on a short page, and on a page that repeats the previous one: a
	 * store that ignores `_offset` would otherwise loop for ever.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $schema        The schema.
	 *
	 * @return \Generator<int, array<string, mixed>> The rows.
	 */
	private function pages(object $objectService, string $register, string $schema): \Generator {
		$offset = 0;
		$previous = null;
		while (true) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE, '_offset' => $offset],
			);

			$signature = md5((string)json_encode($rows));
			if ($rows === [] || $signature === $previous) {
				return;
			}

			foreach ($rows as $row) {
				yield $row;
			}

			if (count($rows) < self::PAGE) {
				return;
			}

			$previous = $signature;
			$offset += self::PAGE;
		}//end while
	}//end pages()

	/**
	 * The id a stored reference holds, whether a string or an expanded object.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The id, or ''.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ($value['@self']['id'] ?? '')));
		}

		if (is_string($value) === false && is_int($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end referenceId()
}//end class
