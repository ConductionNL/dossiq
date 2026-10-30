<?php

/**
 * Case type rights are enforced by OpenRegister, on both of its paths.
 *
 * This runs OpenRegister's OWN PermissionHandler, MagicRbacHandler and
 * MagicSearchHandler, from the checkout beside this app, over the shipped case
 * schema (fragments merged) with dossiq's real CaseTypeRightsRowsListener
 * answering the matrix request. The single-object verdict and the SQL list
 * (over SQLite) are asserted to give the same set. Only storage is faked: the
 * case types and organisation roles the listener reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Listener\CaseTypeRightsRowsListener;
use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * OpenRegister's real classes replace the stubs for the whole process once
 * loaded, so each test runs in a process of its own.
 *
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CaseTypeRightsEnforcedAccessTest extends TestCase {

	private const OPENREGISTER = __DIR__ . '/../../../../../openregister';

	/**
	 * Cases: case type, team, status, confidentiality, assignee.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	private const CASES = [
		'c-1' => ['ct-permit', 'team-vergunningen', 'st-ontvangen', 'intern', ''],
		'c-2' => ['ct-permit', 'team-vergunningen', 'st-behandeling', 'intern', ''],
		'c-3' => ['ct-permit', 'team-toezicht', 'st-ontvangen', 'intern', ''],
		'c-4' => ['ct-permit', 'team-vergunningen', 'st-ontvangen', 'geheim', ''],
		'c-5' => ['ct-other', 'team-vergunningen', 'st-behandeling', 'intern', 'dirk'],
	];

	/**
	 * Groups per user.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GROUPS = [
		'anna' => ['intakers', 'team-vergunningen'],
		'bram' => ['vergunningverleners'],
		'carla' => ['toezichthouders'],
		'dirk' => ['team-toezicht'],
	];

	/**
	 * The organisation roles: three functions and two teams.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const ROLES = [
		['id' => 'r-intake', 'roleName' => 'Intaker', 'department' => 'Vergunningen', 'ncGroupId' => 'intakers'],
		['id' => 'r-vv', 'roleName' => 'Vergunningverlener', 'department' => 'Vergunningen', 'ncGroupId' => 'vergunningverleners'],
		['id' => 'r-th', 'roleName' => 'Toezichthouder', 'department' => 'Toezicht', 'ncGroupId' => 'toezichthouders'],
		['id' => 'r-team-v', 'roleName' => 'Team Vergunningen', 'department' => 'Vergunningen', 'ncGroupId' => 'team-vergunningen'],
		['id' => 'r-team-t', 'roleName' => 'Team Toezicht', 'department' => 'Toezicht', 'ncGroupId' => 'team-toezicht'],
	];

	/**
	 * The permit case type's rights, as the board draws them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const RIGHTS = [
		['department' => '$ownTeam', 'role' => 'Intaker', 'confidentiality' => 'intern', 'actions' => ['read', 'update', 'create'], 'statuses' => ['st-ontvangen'], 'documentActions' => ['read', 'add']],
		['department' => '*', 'role' => 'Vergunningverlener', 'confidentiality' => 'geheim', 'actions' => ['read', 'update', 'delete'], 'documentActions' => ['read', 'add', 'delete']],
		['department' => '*', 'role' => 'Toezichthouder', 'confidentiality' => 'intern', 'actions' => ['read'], 'documentActions' => ['read']],
		['department' => '*', 'role' => 'Toezichthouder', 'confidentiality' => 'intern', 'actions' => ['read', 'update'], 'until' => '2000-01-01'],
	];

	/**
	 * Put OpenRegister's real source, and the DBAL it needs, in front of the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$lib = realpath(self::OPENREGISTER . '/lib');
		$dbal = realpath(self::OPENREGISTER . '/vendor/doctrine/dbal/src');
		if ($lib === false || $dbal === false) {
			self::markTestSkipped('No sibling openregister checkout with its vendor at ' . self::OPENREGISTER . '. CI clones it, so this runs there.');
		}

		spl_autoload_register(
			static function (string $class) use ($lib, $dbal): void {
				foreach (['OCA\\OpenRegister\\' => $lib, 'Doctrine\\DBAL\\' => $dbal] as $prefix => $dir) {
					if (str_starts_with($class, $prefix) === true) {
						$file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
						if (is_file($file) === true) {
							require_once $file;
						}
					}
				}
			},
			true,
			true
		);
	}//end setUp()

	/**
	 * The shipped case schema, fragments merged, as OpenRegister's entity.
	 *
	 * @return object The Schema entity.
	 */
	private function caseSchema(): object {
		$settings = __DIR__ . '/../../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
		$case = $merged['components']['schemas']['case'];

		$class = 'OCA\\OpenRegister\\Db\\Schema';
		$schema = new $class();
		$schema->setId(71);
		$schema->setSlug('case');
		$schema->setProperties($case['properties']);
		$schema->setAuthorization($case['authorization']);
		return $schema;
	}//end caseSchema()

	/**
	 * Dossiq's real listener over a faked store and role list.
	 *
	 * @return CaseTypeRightsRowsListener
	 */
	private function listener(): CaseTypeRightsRowsListener {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('everyCaseType')->willReturn(
			[
				['id' => 'ct-permit', 'isDraft' => false, 'rightsMatrix' => self::RIGHTS],
				['id' => 'ct-permit-draft', 'isDraft' => true, 'rightsMatrix' => [['department' => '*', 'role' => 'Intaker', 'confidentiality' => 'zeer_geheim', 'actions' => ['read', 'update']]]],
				['id' => 'ct-other', 'isDraft' => false, 'rightsMatrix' => []],
			]
		);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)$row['id']);

		$roles = $this->getMockBuilder(OrganisationRoles::class)->disableOriginalConstructor()->onlyMethods(['allUnscoped'])->getMock();
		$roles->method('allUnscoped')->willReturn(self::ROLES);

		return new CaseTypeRightsRowsListener(store: $store, roles: $roles, translate: new CaseTypeRightsMatrixRows(), logger: new NullLogger());
	}//end listener()

	/**
	 * OpenRegister's permission handler and list handler for one user.
	 *
	 * @param string $uid The user.
	 *
	 * @return array{0: object, 1: object} PermissionHandler, MagicRbacHandler.
	 */
	private function handlers(string $uid): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(self::GROUPS[$uid]);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => in_array($group, self::GROUPS[$uid], true));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);

		$listener = $this->listener();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static fn (object $event) => $listener->handle($event));

		$schemas = $this->createMock('OCA\\OpenRegister\\Db\\SchemaMapper');
		$registers = $this->createMock('OCA\\OpenRegister\\Db\\RegisterMapper');
		$objects = $this->createMock('OCA\\OpenRegister\\Db\\MagicMapper');
		$evaluatorClass = 'OCA\\OpenRegister\\Service\\OperatorEvaluator';
		$evaluator = new $evaluatorClass(new NullLogger());
		$memberOfClass = 'OCA\\OpenRegister\\Service\\Rbac\\MemberOfResolver';
		$matcherClass = 'OCA\\OpenRegister\\Service\\ConditionMatcher';
		$container = $this->createMock(ContainerInterface::class);
		// OpenRegister resolves MagicMapper through the container from ecd4e6c8 on; an
		// older checkout takes the mapper itself as the third argument.
		$third = (new \ReflectionMethod(objectOrMethod: $memberOfClass, method: '__construct'))->getParameters()[2]->getType();
		$memberOfThird = $objects;
		if ($third instanceof \ReflectionNamedType && $third->getName() === ContainerInterface::class) {
			$memberOfThird = $container;
		}

		$memberOf = new $memberOfClass($schemas, $registers, $memberOfThird, $evaluator, new NullLogger());
		$matcher = new $matcherClass($session, $container, $evaluator, new NullLogger(), $groups, $memberOf);

		$permissionClass = 'OCA\\OpenRegister\\Service\\Object\\PermissionHandler';
		$permissions = new $permissionClass($session, $users, $groups, $schemas, $objects, $matcher, $appConfig, new NullLogger(), $container, $dispatcher);

		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissions, $registers, $objects, $permissionClass) {
				if ($id === $permissionClass) {
					return $permissions;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\RegisterMapper') {
					return $registers;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\MagicMapper') {
					return $objects;
				}

				throw new \RuntimeException('Not in this test: ' . $id);
			}
		);

		$rbacClass = 'OCA\\OpenRegister\\Db\\MagicMapper\\MagicRbacHandler';
		return [$permissions, new $rbacClass($session, $groups, $users, $appConfig, $matcher, $container, new NullLogger())];
	}//end handlers()

	/**
	 * The cases the single-object verdict admits for one action.
	 *
	 * @param string $uid    The user.
	 * @param string $action The action.
	 *
	 * @return array<int, string>
	 */
	private function admitted(string $uid, string $action): array {
		[$permissions] = $this->handlers(uid: $uid);
		$schema = $this->caseSchema();
		$entityClass = 'OCA\\OpenRegister\\Db\\ObjectEntity';

		$admitted = [];
		foreach (self::CASES as $uuid => [$caseType, $team, $status, $level, $assignee]) {
			$object = new $entityClass();
			$object->setUuid($uuid);
			$object->setOwner('intake');
			$object->setObject(['caseType' => $caseType, 'assignedGroup' => $team, 'status' => $status, 'confidentiality' => $level, 'assignee' => $assignee]);
			if ($permissions->hasPermission(schema: $schema, action: $action, userId: $uid, objectOwner: 'intake', object: $object) === true) {
				$admitted[] = $uuid;
			}
		}

		return $admitted;
	}//end admitted()

	/**
	 * The cases the SQL list returns for one user.
	 *
	 * @param string $uid The user.
	 *
	 * @return array<int, string>
	 */
	private function listed(string $uid): array {
		[, $rbac] = $this->handlers(uid: $uid);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'");
		$queryBuilder = $this->createMock('OCP\\DB\\QueryBuilder\\IQueryBuilder');
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$platform = 'Doctrine\\DBAL\\Platforms\\SqlitePlatform';
		$db->method('getDatabasePlatform')->willReturn(new $platform());

		$organisationClass = 'OCA\\OpenRegister\\Db\\MagicMapper\\MagicOrganizationHandler';
		$organisation = $this->createMock($organisationClass);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => constant($organisationClass . '::SCOPE_IN'), 'uuids' => ['org-home']]);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		$searchClass = 'OCA\\OpenRegister\\Db\\MagicMapper\\MagicSearchHandler';
		$search = new $searchClass(
			$db,
			new NullLogger(),
			$rbac,
			$organisation,
			$this->createMock('OCA\\OpenRegister\\Service\\Object\\SchemaTypeConverter'),
			$this->createMock('OCA\\OpenRegister\\Service\\DateTimeNormalizer'),
			relatedRows: $this->createMock('OCA\\OpenRegister\\Service\\Query\\RelatedRowQueryApplier')
		);
		$conditions = $search->buildWhereConditionsSql(query: [], schema: $this->caseSchema());

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT, _deleted TEXT, _archived TEXT,'
			. ' _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			. ' case_type TEXT, assigned_group TEXT, status TEXT, confidentiality TEXT, assignee TEXT)'
		);
		$insert = $pdo->prepare(
			"INSERT INTO t (_uuid, _owner, _organisation, case_type, assigned_group, status, confidentiality, assignee) VALUES (?, 'intake', 'org-home', ?, ?, ?, ?, ?)"
		);
		foreach (self::CASES as $uuid => $row) {
			$insert->execute(array_merge([$uuid], $row));
		}

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		return $pdo->query('SELECT _uuid FROM t' . $where . ' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);
	}//end listed()

	/**
	 * An intaker reads their own team's cases up to intern, on both paths.
	 *
	 * @return void
	 */
	public function testAnIntakerReadsTheirOwnTeamOnBothPaths(): void {
		self::assertSame(['c-1', 'c-2'], $this->admitted(uid: 'anna', action: 'read'));
		self::assertSame(['c-1', 'c-2'], $this->listed(uid: 'anna'));
	}//end testAnIntakerReadsTheirOwnTeamOnBothPaths()

	/**
	 * An intaker edits, creates and adds documents only in Ontvangen; reads files in every status.
	 *
	 * @return void
	 */
	public function testAnIntakerEditsOnlyInTheRowsStatuses(): void {
		self::assertSame(['c-1'], $this->admitted(uid: 'anna', action: 'update'));
		self::assertSame(['c-1'], $this->admitted(uid: 'anna', action: 'create'));
		self::assertSame(['c-1'], $this->admitted(uid: 'anna', action: 'addFiles'));
		self::assertSame(['c-1', 'c-2'], $this->admitted(uid: 'anna', action: 'readFiles'));
		self::assertSame([], $this->admitted(uid: 'anna', action: 'deleteFiles'));
	}//end testAnIntakerEditsOnlyInTheRowsStatuses()

	/**
	 * A permit officer at geheim reads and changes every permit case, of every level below.
	 *
	 * @return void
	 */
	public function testALevelCoversTheLowerLevels(): void {
		self::assertSame(['c-1', 'c-2', 'c-3', 'c-4'], $this->admitted(uid: 'bram', action: 'read'));
		self::assertSame(['c-1', 'c-2', 'c-3', 'c-4'], $this->listed(uid: 'bram'));
		self::assertSame(['c-1', 'c-2', 'c-3', 'c-4'], $this->admitted(uid: 'bram', action: 'deleteFiles'));
	}//end testALevelCoversTheLowerLevels()

	/**
	 * An inspector reads files and changes nothing; the expired edit row grants nothing.
	 *
	 * @return void
	 */
	public function testAnInspectorReadsOnly(): void {
		self::assertSame(['c-1', 'c-2', 'c-3'], $this->admitted(uid: 'carla', action: 'read'));
		self::assertSame(['c-1', 'c-2', 'c-3'], $this->listed(uid: 'carla'));
		self::assertSame(['c-1', 'c-2', 'c-3'], $this->admitted(uid: 'carla', action: 'readFiles'));
		self::assertSame([], $this->admitted(uid: 'carla', action: 'update'));
		self::assertSame([], $this->admitted(uid: 'carla', action: 'addFiles'));
	}//end testAnInspectorReadsOnly()

	/**
	 * The assignee keeps their own case, which has no rights rows, outside any status.
	 *
	 * @return void
	 */
	public function testTheAssigneeKeepsTheirOwnRights(): void {
		self::assertSame(['c-5'], $this->admitted(uid: 'dirk', action: 'read'));
		self::assertSame(['c-5'], $this->listed(uid: 'dirk'));
		self::assertSame(['c-5'], $this->admitted(uid: 'dirk', action: 'update'));
		self::assertSame(['c-5'], $this->admitted(uid: 'dirk', action: 'addFiles'));
	}//end testTheAssigneeKeepsTheirOwnRights()
}//end class
