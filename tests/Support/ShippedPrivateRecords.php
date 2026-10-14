<?php

/**
 * The people on a case against the register as it ships
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who reads and who updates a private record of a case, from what the register ships.
 *
 * Three real parts and one model. Real: the merged register the importer
 * reads (a case type's `reviewerAccess` and each record schema's
 * authorization block), CaseReviewerAccess, and CaseRecordReaders under it.
 * Model: privateVerdict() decides as OpenRegister's
 * `PermissionHandler::privateScopeVerdict()` does on a private object (owner,
 * administrator, owning group, or a grant that carries the action), and
 * admits() puts the schema's group list under it as the ceiling of a grant.
 * PrivateScopeVerdictDriftTest holds privateVerdict() to OpenRegister's own
 * method: it runs the real class over the same inputs. The group list rule
 * in admits() is still a reading of OpenRegister's rule chain, and the
 * refusal on a running instance is the live check of the change that uses
 * this.
 */
class ShippedPrivateRecords {

	/**
	 * The private scope admits the caller whatever the schema's rule says.
	 */
	public const ADMIT = 'admit';

	/**
	 * The private scope refuses the caller.
	 */
	public const DENY = 'deny';

	/**
	 * The private scope does not decide; the schema's rule does.
	 */
	public const NOT_PRIVATE = 'not-private';

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged = [];

	/**
	 * The rows of the case.
	 *
	 * @var InMemoryRegister
	 */
	public InMemoryRegister $rows;

	/**
	 * What the reconcile granted: permission bits by record uuid and user.
	 *
	 * @var array<string, array<string, int>>
	 */
	public array $grants = [];

	/**
	 * The owning group the reconcile set, by record uuid.
	 *
	 * @var array<string, string>
	 */
	public array $ownerGroups = [];

	/**
	 * The groups each user is in.
	 *
	 * @var array<string, list<string>>
	 */
	public array $groups = [];

	/**
	 * Who is signed in; '' is nobody, as during an upgrade.
	 *
	 * @var string
	 */
	public string $signedIn = '';

	/**
	 * Every user the session was switched to, in order; '' is back to nobody.
	 *
	 * @var list<string>
	 */
	public array $sessions = [];

	/**
	 * Users whose account is disabled.
	 *
	 * @var list<string>
	 */
	public array $disabled = [];

	/**
	 * Whether a grant is refused unless an administrator or a holder of `grant_manage` is signed in, as OpenRegister refuses it.
	 *
	 * @var bool
	 */
	public bool $grantsAreChecked = false;

	/**
	 * The uid the app config names as dossiq's background service account; '' is none chosen.
	 *
	 * @var string
	 */
	public string $serviceAccount = 'dossiq-achtergrond';

	/**
	 * Who holds OpenRegister's special right `grant_manage`, as giveInEveryOrganisation() stored it.
	 *
	 * @var list<array{group: string, register: ?string, schemas: ?list<string>}>
	 */
	public array $grantManage = [];

	/**
	 * Whether this OpenRegister has special rights at all.
	 *
	 * @var bool
	 */
	public bool $hasSpecialRights = true;

	/**
	 * Read the register as the importer does.
	 *
	 * @param TestCase $test The test, for its mocks.
	 */
	public function __construct(private readonly TestCase $test) {
		$settings = dirname(__DIR__, 2) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$this->merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
		$this->rows = new InMemoryRegister();
	}//end __construct()

	/**
	 * The shipped case type row with this slug.
	 *
	 * @param string $slug The case type slug.
	 *
	 * @return array<string, mixed>
	 */
	public function caseType(string $slug): array {
		foreach (($this->merged['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') === 'caseType' && ($object['@self']['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return [];
	}//end caseType()

	/**
	 * Reconcile one case of the shipped case type and keep what was granted.
	 *
	 * @param string $caseTypeSlug The case type slug.
	 * @param string $caseId       The case uuid; its row must be seeded under `case`.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 */
	public function reconcile(string $caseTypeSlug, string $caseId): array {
		return $this->access(caseTypeSlug: $caseTypeSlug)->reconcile(caseId: $caseId);
	}//end reconcile()

	/**
	 * The real CaseReviewerAccess over the shipped case type and this fixture's rows and grants.
	 *
	 * @param string $caseTypeSlug The case type slug.
	 *
	 * @return CaseReviewerAccess
	 */
	public function access(string $caseTypeSlug): CaseReviewerAccess {
		[$settings, $caseTypes, $resolver, $groupManager] = $this->parts(caseTypeSlug: $caseTypeSlug);

		$grants = $this->mock(ObjectReadGrants::class);
		$grants->method('available')->willReturn(true);
		$grants->method('reconcile')->willReturnCallback(
			function (string $schema, string $uuid, array $readers, string $group): array {
				// OpenRegister writes a grant only for a signed-in owner, owning group member, administrator
				// or holder of the special right grant_manage on the record's schema (decision 234).
				if ($this->grantsAreChecked === true && $this->mayManageGrants(user: $this->signedIn, schema: $schema) === false) {
					return ['granted' => 0, 'revoked' => 0, 'refused' => 1];
				}

				$held = ($this->grants[$uuid] ?? []);
				$this->grants[$uuid] = [];
				$granted = 0;
				foreach ($readers as $user => $permissions) {
					$this->grants[$uuid][(string)$user] = $permissions;
					if (($held[(string)$user] ?? 0) !== $permissions) {
						$granted++;
					}
				}

				$this->ownerGroups[$uuid] = $group;
				return ['granted' => $granted, 'revoked' => count(array_diff_key($held, $readers)), 'refused' => 0];
			}
		);

		return new CaseReviewerAccess(
			settingsService: $settings,
			caseTypes: $caseTypes,
			resolver: $resolver,
			grants: $grants,
			groupManager: $groupManager,
			logger: $this->mock(LoggerInterface::class),
		);
	}//end access()

	/**
	 * The real backfill over the shipped case type, with a session that starts with nobody signed in.
	 *
	 * The session follows `signedIn`, and the administrators are the users this
	 * fixture puts in the `admin` group.
	 *
	 * @param string          $caseTypeSlug The case type slug.
	 * @param LoggerInterface $logger       The logger.
	 *
	 * @return CaseRecordGrantsBackfill
	 */
	public function backfill(string $caseTypeSlug, LoggerInterface $logger): CaseRecordGrantsBackfill {
		[$settings, $caseTypes, $resolver, $groupManager] = $this->parts(caseTypeSlug: $caseTypeSlug);

		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn === '' ? null : $this->user(uid: $this->signedIn));
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->signedIn = (string)$user?->getUID();
				$this->sessions[] = $this->signedIn;
			}
		);

		$admins = $this->mock(IGroup::class);
		$admins->method('getUsers')->willReturnCallback(
			fn (): array => array_map(
				fn (string $uid): IUser => $this->user(uid: $uid),
				array_keys(array_filter($this->groups, static fn (array $groups): bool => in_array('admin', $groups, true)))
			)
		);
		$groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'admin' ? $admins : null);
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === '' ? null : $this->user(uid: $uid));
		$appConfig = $this->mock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === BackgroundServiceAccount::CONFIG_KEY ? $this->serviceAccount : $default
		);
		$serviceAccount = new BackgroundServiceAccount($appConfig, $users, $groupManager, $session, $this->mock(LoggerInterface::class), $this->mock(INotificationManager::class));

		$specialRights = new class($this) {
			public function __construct(private readonly ShippedPrivateRecords $shipped) {
			}

			public function giveInEveryOrganisation(string $right, string $group, ?string $register = null, ?array $schemas = null): array {
				return $this->shipped->give(right: $right, group: $group, register: $register, schemas: $schemas);
			}
		};
		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($specialRights): object {
				if ($name !== CaseRecordGrantsBackfill::SPECIAL_RIGHTS || $this->hasSpecialRights === false) {
					throw new \RuntimeException('no such service: '.$name);
				}

				return $specialRights;
			}
		);

		return new CaseRecordGrantsBackfill(
			settingsService: $settings,
			caseTypes: $caseTypes,
			resolver: $resolver,
			access: $this->access(caseTypeSlug: $caseTypeSlug),
			serviceAccount: $serviceAccount,
			userSession: $session,
			container: $container,
			logger: $logger,
		);
	}//end backfill()

	/**
	 * Store the special right as OpenRegister's giveInEveryOrganisation() does: one entry per group.
	 *
	 * @param string            $right    The right; only `grant_manage` is modelled.
	 * @param string            $group    The group.
	 * @param string|null       $register The register it is narrowed to.
	 * @param list<string>|null $schemas  The schemas it is narrowed to.
	 *
	 * @return array{organisations: int, changed: int}
	 */
	public function give(string $right, string $group, ?string $register, ?array $schemas): array {
		$entry = ['group' => $group, 'register' => $register, 'schemas' => $schemas];
		$before = $this->grantManage;
		$this->grantManage = array_values(array_filter($this->grantManage, static fn (array $held): bool => $held['group'] !== $group));
		$this->grantManage[] = $entry;

		return ['organisations' => 1, 'changed' => ($before === $this->grantManage ? 0 : 1)];
	}//end give()

	/**
	 * Whether OpenRegister lets this user manage the grants on a record of this schema.
	 *
	 * An administrator, or a member of a group that holds `grant_manage` on the
	 * schema in this register. The record's owner and owning group also may in
	 * OpenRegister; the reconcile never relies on that, so it is not modelled.
	 *
	 * @param string $user   The signed-in user; '' is nobody.
	 * @param string $schema The record's schema slug.
	 *
	 * @return bool
	 */
	public function mayManageGrants(string $user, string $schema): bool {
		$groups = ($this->groups[$user] ?? []);
		if ($user === '' || in_array('admin', $groups, true) === true) {
			return $user !== '';
		}

		foreach ($this->grantManage as $entry) {
			if (in_array($entry['group'], $groups, true) === true
				&& ($entry['register'] === null || $entry['register'] === 'dossiq')
				&& ($entry['schemas'] === null || in_array($schema, $entry['schemas'], true) === true)
			) {
				return true;
			}
		}

		return false;
	}//end mayManageGrants()

	/**
	 * A user of this fixture; disabled when listed in `disabled`.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(in_array($uid, $this->disabled, true) === false);

		return $user;
	}//end user()

	/**
	 * The settings, case type store, resolver and group manager every service here is built on.
	 *
	 * @param string $caseTypeSlug The case type slug.
	 *
	 * @return array{0: SettingsService, 1: CaseTypeStore, 2: CaseTypeResolver, 3: IGroupManager}
	 */
	private function parts(string $caseTypeSlug): array {
		$caseType = $this->caseType(slug: $caseTypeSlug);
		// Each schema setting answers its own slug, so a row is seeded under the slug the register ships.
		$config = (['register' => 'dossiq'] + array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY));

		$settings = $this->mock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->rows);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default));

		$resolver = $this->mock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $caseTypeId): array => $caseTypeId === (string)($caseType['id'] ?? '') ? $caseType : []
		);

		$caseTypes = (fn (): object => $this->getMockBuilder(CaseTypeStore::class)->setConstructorArgs([$settings])->onlyMethods(['everyCaseType'])->getMock())
			->call($this->test);
		$caseTypes->method('everyCaseType')->willReturn([$caseType, ['id' => 'a-case-type-that-keeps-nothing-private']]);
		// The upgrade's walk reads the case types from the store itself, not from that listing.
		if (($config['case_type_schema'] ?? '') !== '' && (string)($caseType['id'] ?? '') !== '') {
			$this->rows->seed($config['case_type_schema'], (string)$caseType['id'], $caseType);
			$this->rows->seed($config['case_type_schema'], 'a-case-type-that-keeps-nothing-private', []);
		}

		$groupManager = $this->mock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', ($this->groups[$uid] ?? []), true));
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($this->groups[$uid] ?? []), true));

		return [$settings, $caseTypes, $resolver, $groupManager];
	}//end parts()

	/**
	 * Whether OpenRegister admits this user on this record, as the model reads its rule.
	 *
	 * @param string $schema The record's schema slug in the shipped register.
	 * @param string $uuid   The record uuid.
	 * @param string $user   The user.
	 * @param string $action `read` or `update`.
	 *
	 * @return bool
	 */
	public function admits(string $schema, string $uuid, string $user, string $action): bool {
		$groups = ($this->groups[$user] ?? []);
		$authorization = (array)($this->merged['components']['schemas'][$schema]['authorization'] ?? []);
		$ownerGroup = ($this->ownerGroups[$uuid] ?? '');
		$verdict = self::privateVerdict(
			authorization: $authorization,
			record: [
				'owner' => (string)(($this->rows->row(schema: $schema, uuid: $uuid)['@self'] ?? [])['owner'] ?? ''),
				'authorization' => ($ownerGroup === '' ? null : ['ownerGroup' => $ownerGroup]),
			],
			user: $user,
			userGroups: $groups,
			granted: ($this->grants[$uuid][$user] ?? 0),
			action: $action
		);
		if ($verdict !== self::NOT_PRIVATE) {
			return $verdict === self::ADMIT;
		}

		// Not private, or re-opened by a grant: the schema's own rule decides, so it is the
		// ceiling of a grant. An administrator passes that rule whatever it lists.
		return in_array('admin', $groups, true) === true || array_intersect($groups, (array)($authorization[$action] ?? [])) !== [];
	}//end admits()

	/**
	 * The model of OpenRegister's `PermissionHandler::privateScopeVerdict()`.
	 *
	 * Kept equal to the real method by PrivateScopeVerdictDriftTest, which runs
	 * OpenRegister's own class over the same inputs and fails on any difference.
	 * `not-private` means OpenRegister declines to decide here and its ordinary
	 * rule chain runs: for a record with no private scope, for a create, and
	 * for a caller whose grant carries the action.
	 *
	 * @param array<string, mixed>|null                                        $authorization The cascaded block: the schema's, unless the record overrides it.
	 * @param array{owner: ?string, authorization: ?array<string, mixed>}|null $record        The record's owner and own block; null when no record is asked about.
	 * @param string                                                           $user          The caller.
	 * @param list<string>                                                     $userGroups    The caller's groups.
	 * @param int                                                              $granted       Core permission bits the caller holds on the record through a grant.
	 * @param string                                                           $action        The action.
	 *
	 * @return string `admit`, `deny` or `not-private`.
	 */
	public static function privateVerdict(?array $authorization, ?array $record, string $user, array $userGroups, int $granted, string $action): string {
		if ($record === null || $action === 'create') {
			return self::NOT_PRIVATE;
		}

		$scope = ($authorization['scope'] ?? null);
		if ($scope === null || $scope === '' || $scope === 'organisation') {
			return self::NOT_PRIVATE;
		}

		$ownerGroup = ($record['authorization']['ownerGroup'] ?? null);
		if (in_array('admin', $userGroups, true) === true
			|| (is_string($ownerGroup) === true && $ownerGroup !== '' && in_array($ownerGroup, $userGroups, true) === true)
			|| ($record['owner'] !== null && $record['owner'] === $user)
		) {
			return self::ADMIT;
		}

		$bit = (['read' => 1, 'update' => 2, 'create' => 4, 'delete' => 8, 'share' => 16][$action] ?? null);
		if ($bit !== null && ($granted & $bit) === $bit) {
			return self::NOT_PRIVATE;
		}

		return self::DENY;
	}//end privateVerdict()

	/**
	 * A mock from the test that owns this fixture.
	 *
	 * @param class-string $class The class.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function mock(string $class): object {
		return (fn (string $class): object => $this->createMock($class))->call($this->test, $class);
	}//end mock()
}//end class
