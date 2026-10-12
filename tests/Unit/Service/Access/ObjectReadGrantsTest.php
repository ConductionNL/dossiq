<?php

/**
 * Object Read Grants Test
 *
 * One record's user read grants and owning group reconciled through
 * OpenRegister's sharing and ownership services, doubled by name with the
 * signatures read on openregister development (10 Oct 2026):
 * `ObjectSharingService::listGrants(ObjectEntity)`, `grant(ObjectEntity,
 * string $type, string $shareWith, int $permissions)`, `revoke(ObjectEntity,
 * string $shareId)`, and `ObjectOwnershipService::setOwnerGroup(Register,
 * Schema, ObjectEntity, ?string)`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Access\ObjectReadGrants
 */
class ObjectReadGrantsTest extends TestCase {

	/** @var object The record entity double. */
	private object $record;

	/** @var object The sharing service double. */
	private object $sharing;

	/** @var object The ownership service double. */
	private object $ownership;

	/** @var array<string, object> */
	private array $services = [];

	private ObjectReadGrants $grants;

	protected function setUp(): void {
		$this->record = new class {
			/** @var array<string, mixed> */
			public array $authorization = [];

			public function getOwner(): string {
				return 'reviewer1';
			}

			public function getUuid(): string {
				return 'a-1';
			}

			/** @return array<string, mixed> */
			public function getAuthorization(): array {
				return $this->authorization;
			}
		};

		$this->sharing = new class {
			/** @var list<array<string, mixed>> */
			public array $grants = [];

			/** @var list<string> */
			public array $granted = [];

			/** @var list<int> */
			public array $permissions = [];

			/** @var list<string> */
			public array $revoked = [];

			public bool $refuse = false;

			/** @return list<array<string, mixed>> */
			public function listGrants(object $object): array {
				if ($this->refuse === true) {
					throw new RuntimeException('NotAuthorizedException');
				}

				return $this->grants;
			}

			/** @return array<string, mixed> */
			public function grant(object $object, string $type, string $shareWith, int $permissions = 1, array $verbs = []): array {
				$this->granted[] = $type . ':' . $shareWith;
				$this->permissions[] = $permissions;
				return [];
			}

			public function revoke(object $object, string $shareId): void {
				$this->revoked[] = $shareId;
			}
		};

		$this->ownership = new class {
			/** @var list<string|null> */
			public array $groups = [];

			/** @return array<string, mixed> */
			public function setOwnerGroup(object $register, object $schema, object $object, ?string $group): array {
				$this->groups[] = $group;
				$object->authorization['ownerGroup'] = $group;
				return [];
			}
		};

		$mapper = new class {
			public function find(string $slug): object {
				return new \stdClass();
			}
		};

		$record = $this->record;
		$objectService = new class ($record) {
			public function __construct(private object $record) {
			}

			public function find(string $id, mixed $_extend = null, bool $files = false, string $register = '', string $schema = ''): ?object {
				return $id === 'a-1' ? $this->record : null;
			}
		};

		$this->services = [
			'OCA\OpenRegister\Service\Rbac\ObjectSharingService' => $this->sharing,
			'OCA\OpenRegister\Service\Rbac\ObjectOwnershipService' => $this->ownership,
			'OCA\OpenRegister\Db\RegisterMapper' => $mapper,
			'OCA\OpenRegister\Db\SchemaMapper' => $mapper,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name): object {
				if (isset($this->services[$name]) === false) {
					throw new RuntimeException('not registered: ' . $name);
				}

				return $this->services[$name];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ['register' => 'dossiq'][$key] ?? $default);

		$this->grants = new ObjectReadGrants(settingsService: $settings, container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	public function testReadersAreGrantedReadOnlyAndTheOwnerIsSkipped(): void {
		$answer = $this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1, 'reviewer1' => 1, 'reviewer2' => 1, '' => 1, ' ' => 3], group: 'dossiq-coordinators');

		self::assertSame(['user:handler', 'user:reviewer2'], $this->sharing->granted);
		self::assertSame([ObjectReadGrants::READ, ObjectReadGrants::READ], $this->sharing->permissions, 'no update, delete or share bit is ever granted');
		self::assertSame(['granted' => 2, 'revoked' => 0, 'refused' => 0], $answer);
		self::assertSame(['dossiq-coordinators'], $this->ownership->groups);
	}//end testReadersAreGrantedReadOnlyAndTheOwnerIsSkipped()

	public function testAFormerReaderIsRevokedAndAGroupOrInheritedGrantIsLeftAlone(): void {
		$this->sharing->grants = [
			['id' => 'ocinternal:1', 'type' => 'user', 'sharedWith' => 'handler'],
			['id' => 'ocinternal:2', 'type' => 'user', 'sharedWith' => 'former-handler'],
			['id' => 'ocinternal:3', 'type' => 'group', 'sharedWith' => 'archive'],
			['id' => 'inherited:4', 'type' => 'user', 'sharedWith' => 'parent-reader', 'inherited' => true],
		];

		$answer = $this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1], group: 'dossiq-coordinators');

		self::assertSame(['ocinternal:2'], $this->sharing->revoked);
		self::assertSame([], $this->sharing->granted);
		self::assertSame(['granted' => 0, 'revoked' => 1, 'refused' => 0], $answer);
	}//end testAFormerReaderIsRevokedAndAGroupOrInheritedGrantIsLeftAlone()

	public function testASecondRunChangesNothing(): void {
		$this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1], group: 'dossiq-coordinators');
		$this->sharing->grants = [['id' => 'ocinternal:9', 'type' => 'user', 'sharedWith' => 'handler']];
		$this->sharing->granted = [];

		$answer = $this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1], group: 'dossiq-coordinators');

		self::assertSame(['granted' => 0, 'revoked' => 0, 'refused' => 0], $answer);
		self::assertSame(['dossiq-coordinators'], $this->ownership->groups, 'the owning group is set once');
	}//end testASecondRunChangesNothing()

	public function testAReaderWhoMayUpdateIsGrantedReadAndUpdateAndNothingMore(): void {
		$answer = $this->grants->reconcile(schema: 'review', uuid: 'a-1', readers: ['handler' => 1, 'reviewer2' => 3, 'reviewer3' => 31, 'reviewer4' => 2], group: '');

		self::assertSame(['user:handler', 'user:reviewer2', 'user:reviewer3', 'user:reviewer4'], $this->sharing->granted);
		self::assertSame([1, 3, 3, 3], $this->sharing->permissions, 'read always, update when asked, never delete or share');
		self::assertSame(4, $answer['granted']);
	}//end testAReaderWhoMayUpdateIsGrantedReadAndUpdateAndNothingMore()

	public function testAGrantWithOtherPermissionsThanDueIsGrantedAgain(): void {
		$this->sharing->grants = [
			['id' => 'ocinternal:1', 'type' => 'user', 'sharedWith' => 'reviewer2', 'permissions' => 1],
			['id' => 'ocinternal:2', 'type' => 'user', 'sharedWith' => 'handler', 'permissions' => 3],
			['id' => 'ocinternal:3', 'type' => 'user', 'sharedWith' => 'reviewer3', 'permissions' => 3],
		];

		$answer = $this->grants->reconcile(schema: 'review', uuid: 'a-1', readers: ['reviewer2' => 3, 'handler' => 1, 'reviewer3' => 3], group: '');

		self::assertSame(['ocinternal:1', 'ocinternal:2'], $this->sharing->revoked, 'a read grant where update is due, and an update grant where read is due');
		self::assertSame(['user:reviewer2', 'user:handler'], $this->sharing->granted);
		self::assertSame([3, 1], $this->sharing->permissions);
		self::assertSame(['granted' => 2, 'revoked' => 2, 'refused' => 0], $answer);
	}//end testAGrantWithOtherPermissionsThanDueIsGrantedAgain()

	public function testASecondRunWithUpdateGrantsChangesNothing(): void {
		$this->sharing->grants = [
			['id' => 'ocinternal:1', 'type' => 'user', 'sharedWith' => 'reviewer2', 'permissions' => 3],
			['id' => 'ocinternal:2', 'type' => 'user', 'sharedWith' => '1001', 'permissions' => 1],
		];

		$answer = $this->grants->reconcile(schema: 'review', uuid: 'a-1', readers: ['reviewer2' => 3, '1001' => 1], group: '');

		self::assertSame(['granted' => 0, 'revoked' => 0, 'refused' => 0], $answer, 'a numeric user id is matched as the string it is');
	}//end testASecondRunWithUpdateGrantsChangesNothing()

	public function testARefusalIsCountedNotSwallowed(): void {
		$this->sharing->refuse = true;

		self::assertSame(['granted' => 0, 'revoked' => 0, 'refused' => 1], $this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1], group: ''));
		self::assertSame(1, $this->grants->reconcile(schema: 'assessment', uuid: 'missing', readers: ['handler' => 1], group: '')['refused'], 'a record that cannot be read is refused');
	}//end testARefusalIsCountedNotSwallowed()

	public function testWithoutSharingItIsNotAvailable(): void {
		self::assertTrue($this->grants->available());

		unset($this->services['OCA\OpenRegister\Service\Rbac\ObjectSharingService']);

		self::assertFalse($this->grants->available());
		self::assertSame(1, $this->grants->reconcile(schema: 'assessment', uuid: 'a-1', readers: ['handler' => 1], group: '')['refused']);
	}//end testWithoutSharingItIsNotAvailable()
}//end class
