<?php

/**
 * dossiq's model of OpenRegister's private scope rule is held to the rule itself.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Support
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Tests\Support\ShippedPrivateRecords;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * The access tests over the shipped register decide who reads a private
 * record with `ShippedPrivateRecords::privateVerdict()`, dossiq's own reading
 * of `OCA\OpenRegister\Service\Object\PermissionHandler::privateScopeVerdict()`.
 * A model of another app's rule drifts from the rule in silence: OpenRegister
 * changes who a private record admits, the model keeps its old answer, and
 * every access test stays green about an instance that now behaves otherwise.
 *
 * So this runs OpenRegister's OWN method, from the checkout that sits beside
 * this app, over every combination of scope, record, caller, grant and
 * action, and fails on the first answer the model gives differently. Real:
 * PermissionHandler (the private method, reached by reflection), its
 * ObjectScopeResolver, ObjectGrantResolver::isGranted() with the action to
 * permission map, and ObjectEntity. Doubled: the user and group managers, and
 * the one method that reads core's shares.
 *
 * It runs in a process of its own. The suite's autoloader answers dossiq's
 * stubs for `OCA\OpenRegister\`, and a real class loaded in the shared
 * process would replace those stubs for every test after this one.
 *
 * A changed shape (the method gone, renamed arguments, a collaborator
 * renamed) is a failure, not a skip: that is the drift this exists for. Only
 * a missing checkout skips, as StubApiDriftTest does; CI clones it.
 *
 * @coversNothing
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class PrivateScopeVerdictDriftTest extends TestCase {

	/**
	 * Where CI and a local fleet checkout put OpenRegister: beside this app.
	 */
	private const OPENREGISTER_LIB = __DIR__ . '/../../../../openregister/lib';

	private const HANDLER = 'OCA\\OpenRegister\\Service\\Object\\PermissionHandler';

	private const SCOPE_RESOLVER = 'OCA\\OpenRegister\\Service\\Rbac\\ObjectScopeResolver';

	private const GRANT_RESOLVER = 'OCA\\OpenRegister\\Service\\Rbac\\ObjectGrantResolver';

	private const ENTITY = 'OCA\\OpenRegister\\Db\\ObjectEntity';

	private const RECORD = '11111111-1111-4111-8111-111111111111';

	/**
	 * The user and group manager doubles, by the groups they answer.
	 *
	 * @var array<string, array{0: IUserManager, 1: IGroupManager}>
	 */
	private array $managers = [];

	/**
	 * Put OpenRegister's real source in front of the stubs, for this process only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$lib = realpath(self::OPENREGISTER_LIB);
		if ($lib === false || is_dir($lib) === false) {
			self::markTestSkipped(
				'No sibling openregister checkout at ' . self::OPENREGISTER_LIB
				. ': there is no rule to compare the model against. CI clones it, so this runs there.'
			);
		}

		spl_autoload_register(
			static function (string $class) use ($lib): void {
				$prefix = 'OCA\\OpenRegister\\';
				if (str_starts_with($class, $prefix) === false) {
					return;
				}

				$file = $lib . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file) === true) {
					require_once $file;
				}
			},
			true,
			true
		);
	}//end setUp()

	/**
	 * Every input the rule reads, crossed.
	 *
	 * @return list<array{
	 *     authorization: ?array<string, mixed>, record: ?array{owner: ?string, authorization: ?array<string, mixed>},
	 *     user: string, userGroups: list<string>, granted: int, action: string
	 * }>
	 */
	private function cases(): array {
		$blocks = [
			null,
			['read' => ['dossiq-medewerkers']],
			['scope' => 'private', 'read' => ['dossiq-medewerkers'], 'update' => ['dossiq-medewerkers']],
			['scope' => 'organisation'],
			['scope' => ''],
			['scope' => 'a-value-nobody-declared'],
		];
		$records = [
			null,
			['owner' => 'somebody-else', 'authorization' => null],
			['owner' => 'the-caller', 'authorization' => null],
			['owner' => null, 'authorization' => null],
			['owner' => '', 'authorization' => ['ownerGroup' => 'dossiq-coordinators']],
			['owner' => 'somebody-else', 'authorization' => ['ownerGroup' => '']],
		];
		$memberships = [[], ['dossiq-medewerkers'], ['dossiq-coordinators'], ['admin']];

		$cases = [];
		foreach ($blocks as $authorization) {
			foreach ($records as $record) {
				foreach ($memberships as $userGroups) {
					foreach ([0, 1, 2, 3, 8, 31] as $granted) {
						foreach (['read', 'update', 'create', 'delete', 'share', 'a-verb-nobody-declared'] as $action) {
							$cases[] = [
								'authorization' => $authorization,
								'record' => $record,
								'user' => 'the-caller',
								'userGroups' => $userGroups,
								'granted' => $granted,
								'action' => $action,
							];
						}
					}
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * OpenRegister's own answer for one case.
	 *
	 * @param array<string, mixed> $case One row of cases().
	 *
	 * @return string `admit`, `deny` or `not-private`.
	 */
	private function openRegisterSays(array $case): string {
		$handler = new ReflectionClass(self::HANDLER);
		$instance = $handler->newInstanceWithoutConstructor();

		$key = implode(',', $case['userGroups']);
		if (isset($this->managers[$key]) === false) {
			$userManager = $this->createStub(IUserManager::class);
			$userManager->method('get')->willReturn($this->createStub(IUser::class));
			$groupManager = $this->createStub(IGroupManager::class);
			$groupManager->method('getUserGroupIds')->willReturn($case['userGroups']);
			$this->managers[$key] = [$userManager, $groupManager];
		}

		[$userManager, $groupManager] = $this->managers[$key];

		// The parent is bound when this line runs, which is after setUp() put the real source in front.
		$grants = new class ([$case['user'] => [self::RECORD => $case['granted']]]) extends \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver {

			/**
			 * No parent constructor: nothing here reads the container or core's shares.
			 *
			 * @param array<string, array<string, int>> $held Permission bits by user and record uuid.
			 */
			public function __construct(private readonly array $held) {
			}//end __construct()

			/**
			 * The grants of one user, in place of the read of core's shares.
			 *
			 * @param string|null $userId The user.
			 *
			 * @return array<string, int>
			 */
			public function grantedObjectUuids(?string $userId): array {
				return array_filter(($this->held[(string)$userId] ?? []), static fn (int $bits): bool => $bits > 0);
			}//end grantedObjectUuids()
		};

		$collaborators = [
			'userManager' => $userManager,
			'groupManager' => $groupManager,
			'objectScopeResolver' => new (self::SCOPE_RESOLVER)(),
			'objectGrantResolver' => $grants,
		];
		foreach ($collaborators as $property => $value) {
			if ($handler->hasProperty($property) === false) {
				self::fail('PermissionHandler no longer has $' . $property . ': re-read the rule and this test.');
			}

			$handler->getProperty($property)->setValue($instance, $value);
		}

		$object = null;
		if ($case['record'] !== null) {
			$object = new (self::ENTITY)();
			$object->setUuid(self::RECORD);
			$object->setOwner($case['record']['owner']);
			$object->setAuthorization($case['record']['authorization']);
		}

		$answer = $handler->getMethod('privateScopeVerdict')->invoke($instance, $case['authorization'], $object, $case['user'], null, $case['action']);

		return match ($answer) {
			true => ShippedPrivateRecords::ADMIT,
			false => ShippedPrivateRecords::DENY,
			null => ShippedPrivateRecords::NOT_PRIVATE,
		};
	}//end openRegisterSays()

	/**
	 * The comparison is against OpenRegister's file, not against a stub of it.
	 *
	 * @return void
	 */
	public function testTheRuleIsReadFromOpenRegistersOwnSource(): void {
		$lib = (string)realpath(self::OPENREGISTER_LIB);
		foreach ([self::HANDLER, self::SCOPE_RESOLVER, self::GRANT_RESOLVER, self::ENTITY] as $class) {
			self::assertTrue(class_exists($class), 'OpenRegister no longer has ' . $class . ': re-read the rule and this test.');
			self::assertStringStartsWith($lib, (string)(new ReflectionClass($class))->getFileName(), $class . ' must be the real class, not a stub.');
		}

		self::assertTrue(method_exists(self::HANDLER, 'privateScopeVerdict'), 'PermissionHandler::privateScopeVerdict() is gone: the model has no rule to follow.');
		self::assertSame(
			['authorization', 'object', 'userId', 'objectOwner', 'action'],
			array_map(
				static fn (ReflectionParameter $parameter): string => $parameter->getName(),
				(new ReflectionClass(self::HANDLER))->getMethod('privateScopeVerdict')->getParameters()
			),
			'privateScopeVerdict() reads other inputs than the model does.'
		);
	}//end testTheRuleIsReadFromOpenRegistersOwnSource()

	/**
	 * The model answers every case as OpenRegister does.
	 *
	 * @return void
	 */
	public function testTheModelAnswersAsOpenRegisterDoes(): void {
		$cases = $this->cases();
		$differences = [];
		$seen = [];
		foreach ($cases as $case) {
			$real = $this->openRegisterSays(case: $case);
			$seen[$real] = true;
			$model = ShippedPrivateRecords::privateVerdict(...$case);
			if ($model !== $real) {
				$differences[] = 'OpenRegister says ' . $real . ', the model says ' . $model . ' for ' . json_encode($case);
			}
		}

		self::assertSame(
			[],
			array_slice($differences, 0, 10),
			count($differences) . ' of ' . count($cases) . " cases differ.\n"
			. "OpenRegister's private scope rule and tests/Support/ShippedPrivateRecords::privateVerdict() no longer agree,\n"
			. "so the access tests over the shipped register describe a rule OpenRegister does not apply.\n"
			. 'Re-read PermissionHandler::privateScopeVerdict() and change the model, then check what the change means for the grants dossiq writes.'
		);
		ksort($seen);
		self::assertSame(
			[ShippedPrivateRecords::ADMIT, ShippedPrivateRecords::DENY, ShippedPrivateRecords::NOT_PRIVATE],
			array_keys($seen),
			'The cases must reach every answer of the rule, or agreeing on them proves little.'
		);
	}//end testTheModelAnswersAsOpenRegisterDoes()
}//end class
