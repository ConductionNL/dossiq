<?php

/**
 * Dossiq RoleMutationListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\RoleMutationListener;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A role written, changed or removed drops its case's cached roles.
 *
 * @covers \OCA\Dossiq\Listener\RoleMutationListener
 * @uses \OCA\Dossiq\Listener\Support\SerialisedObject
 */
class RoleMutationListenerTest extends TestCase {

	/**
	 * The role resolver.
	 *
	 * @var RoleResolverService&MockObject
	 */
	private RoleResolverService $resolver;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->createMock(RoleResolverService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A created role drops its case's cache.
	 *
	 * @return void
	 */
	public function testACreatedRoleInvalidatesItsCase(): void {
		$this->resolver->expects($this->once())->method('invalidateCache')->with('case-1');

		$this->listener()->handle(new ObjectCreatedEvent($this->entity(schema: 'role', case: 'case-1')));
	}//end testACreatedRoleInvalidatesItsCase()

	/**
	 * A deleted role does too, and a schema given as a path matches by its end.
	 *
	 * @return void
	 */
	public function testADeletedRoleByPathInvalidatesItsCase(): void {
		$this->resolver->expects($this->once())->method('invalidateCache')->with('case-9');

		$this->listener()->handle(new ObjectDeletedEvent($this->entity(schema: 'schemas/role', case: 'case-9')));
	}//end testADeletedRoleByPathInvalidatesItsCase()

	/**
	 * Without `@self` the top-level schema is read.
	 *
	 * @return void
	 */
	public function testATopLevelSchemaIsRead(): void {
		$this->resolver->expects($this->once())->method('invalidateCache')->with('case-3');

		$this->listener()->handle($this->carrying(['schema' => 'role', 'case' => 'case-3']));
	}//end testATopLevelSchemaIsRead()

	/**
	 * Mutations that drop nothing.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function ignored(): array {
		return [
			'another schema' => [['schema' => 'task', 'case' => 'case-1'], 'role'],
			'a schema merely ending alike' => [['schema' => 'userrole', 'case' => 'case-1'], 'role'],
			'no schema' => [['case' => 'case-1'], 'role'],
			'no case' => [['schema' => 'role'], 'role'],
			'no role schema configured' => [['schema' => 'role', 'case' => 'case-1'], ''],
		];
	}//end ignored()

	/**
	 * Another schema, a role without a case or no configured role schema drops
	 * nothing.
	 *
	 * @param array<string, mixed> $object     The object.
	 * @param string               $roleSchema The configured role schema.
	 *
	 * @dataProvider ignored
	 *
	 * @return void
	 */
	public function testNothingIsInvalidated(array $object, string $roleSchema): void {
		$this->resolver->expects($this->never())->method('invalidateCache');

		$this->listener(roleSchema: $roleSchema)->handle($this->carrying($object));
	}//end testNothingIsInvalidated()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->resolver->expects($this->never())->method('invalidateCache');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A failing invalidation is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsSwallowed(): void {
		$this->resolver->method('invalidateCache')->willThrowException(new RuntimeException('cache down'));
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: role mutation listener swallowed exception: cache down');

		$this->listener()->handle(new ObjectCreatedEvent($this->entity(schema: 'role', case: 'case-1')));
	}//end testAFailureIsSwallowed()

	/**
	 * An entity on a schema and case.
	 *
	 * @param string $schema The schema.
	 * @param string $case   The case.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $case): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('role-1');
		$entity->setSchema($schema);
		$entity->setObject(['case' => $case]);

		return $entity;
	}//end entity()

	/**
	 * An update event whose object is the given array.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return Event
	 */
	private function carrying(array $object): Event {
		return new class ($object) extends ObjectUpdatedEvent {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $payload The object.
			 */
			public function __construct(private readonly array $payload) {
				parent::__construct(new ObjectEntity());
			}

			/**
			 * The object.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->payload;
			}
		};
	}//end carrying()

	/**
	 * The listener under test.
	 *
	 * @param string $roleSchema The configured role schema.
	 *
	 * @return RoleMutationListener
	 */
	private function listener(string $roleSchema = 'role'): RoleMutationListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $key === 'role_schema' ? $roleSchema : ''
		);

		return new RoleMutationListener(resolver: $this->resolver, settingsService: $settings, logger: $this->logger);
	}//end listener()
}//end class
