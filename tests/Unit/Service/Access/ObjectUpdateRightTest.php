<?php

/**
 * Tests for ObjectUpdateRight: the update right is what OpenRegister answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\ObjectUpdateRight;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister's evaluator, recording the question it was asked.
 */
class UpdateRightHandlerDouble {

	/**
	 * The named arguments of the last question.
	 *
	 * @var array<string, mixed>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param mixed $answer What to answer.
	 */
	public function __construct(
		private readonly mixed $answer,
	) {
	}//end __construct()

	/**
	 * Answer a permission question.
	 *
	 * @param object      $schema      The schema.
	 * @param string      $action      The verb.
	 * @param string|null $userId      The user.
	 * @param string|null $objectOwner The owner.
	 * @param bool        $_rbac       Whether RBAC applies.
	 * @param object|null $object      The object.
	 *
	 * @return mixed The answer.
	 */
	public function hasPermission(object $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?object $object = null): mixed {
		$this->asked = ['schema' => $schema, 'action' => $action, 'userId' => $userId, 'objectOwner' => $objectOwner, '_rbac' => $_rbac, 'object' => $object];
		return $this->answer;
	}//end hasPermission()
}//end class

/**
 * The update right is OpenRegister's answer, and no answer is a refusal.
 *
 * @covers \OCA\Dossiq\Service\Access\ObjectUpdateRight
 */
class ObjectUpdateRightTest extends TestCase {

	/**
	 * Build the check over a handler and a schema lookup.
	 *
	 * @param object|null $handler   The evaluator, or null when it cannot be resolved.
	 * @param bool        $installed Whether OpenRegister is installed.
	 * @param array       $lookedUp  Collects the schema ids looked up.
	 *
	 * @return ObjectUpdateRight The check.
	 */
	private function right(?object $handler, bool $installed = true, array &$lookedUp = []): ObjectUpdateRight {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);

		$schemaEntity = new \stdClass();
		$mapper = new class($schemaEntity, $lookedUp) {
			/**
			 * Constructor.
			 *
			 * @param object $schema   The schema to answer with.
			 * @param array  $lookedUp Collects the ids.
			 */
			public function __construct(public object $schema, public array &$lookedUp) {
			}//end __construct()

			/**
			 * Find a schema.
			 *
			 * @param string $id The id or slug.
			 *
			 * @return object The schema.
			 */
			public function find(string $id): object {
				$this->lookedUp[] = $id;
				return $this->schema;
			}//end find()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($handler, $mapper): object {
				if ($name === 'OCA\OpenRegister\Db\SchemaMapper') {
					return $mapper;
				}

				if ($handler === null) {
					throw new RuntimeException('no handler');
				}

				return $handler;
			}
		);

		return new ObjectUpdateRight($apps, $container, $this->createMock(LoggerInterface::class));
	}//end right()

	/**
	 * An object double with an owner and a schema, through magic accessors.
	 *
	 * @return object The object.
	 */
	private function object(): object {
		return new class {
			/**
			 * Magic accessors.
			 *
			 * @param string $name The accessor.
			 * @param array  $args The arguments.
			 *
			 * @return string|null The value.
			 */
			public function __call(string $name, array $args): ?string {
				return match ($name) {
					'getOwner' => 'anja',
					'getSchema' => '42',
					default => null,
				};
			}//end __call()
		};
	}//end object()

	/**
	 * The update question carries the object, its owner and its own schema.
	 *
	 * @return void
	 */
	public function testItAsksOpenRegisterAboutUpdate(): void {
		$handler = new UpdateRightHandlerDouble(true);
		$lookedUp = [];
		$object = $this->object();

		$this->assertTrue($this->right($handler, true, $lookedUp)->allows(object: $object, schema: 'inspectionChecklistRun'));
		$this->assertSame('update', $handler->asked['action']);
		$this->assertSame('anja', $handler->asked['objectOwner']);
		$this->assertTrue($handler->asked['_rbac']);
		$this->assertNull($handler->asked['userId']);
		$this->assertSame($object, $handler->asked['object']);
		$this->assertSame(['42'], $lookedUp);
	}//end testItAsksOpenRegisterAboutUpdate()

	/**
	 * A refusal, or anything that is not true, is a refusal.
	 *
	 * @return void
	 */
	public function testOnlyTrueGrants(): void {
		foreach ([false, null, 1, 'yes'] as $answer) {
			$this->assertFalse($this->right(new UpdateRightHandlerDouble($answer))->allows(object: $this->object(), schema: 's'));
		}
	}//end testOnlyTrueGrants()

	/**
	 * When OpenRegister cannot be asked, the write is refused.
	 *
	 * @return void
	 */
	public function testNoAnswerIsARefusal(): void {
		$this->assertFalse($this->right(null)->allows(object: $this->object(), schema: 's'));
		$this->assertFalse($this->right(new UpdateRightHandlerDouble(true), false)->allows(object: $this->object(), schema: 's'));
	}//end testNoAnswerIsARefusal()

	/**
	 * An object that names no schema is checked against the given one, and one without an owner asks for none.
	 *
	 * @return void
	 */
	public function testWithoutItsOwnSchemaOrOwnerTheGivenSchemaAndNoOwnerAreUsed(): void {
		$objects = [
			'no accessors' => new \stdClass(),
			'empty values' => new class {
				/**
				 * The schema, empty.
				 *
				 * @return string The schema.
				 */
				public function getSchema(): string {
					return '';
				}//end getSchema()

				/**
				 * The owner, not a string.
				 *
				 * @return int The owner.
				 */
				public function getOwner(): int {
					return 7;
				}//end getOwner()
			},
		];

		foreach ($objects as $label => $object) {
			$handler = new UpdateRightHandlerDouble(true);
			$lookedUp = [];

			$this->assertTrue($this->right($handler, true, $lookedUp)->allows(object: $object, schema: 'inspectionChecklistRun'), $label);
			$this->assertSame(['inspectionChecklistRun'], $lookedUp, $label);
			$this->assertNull($handler->asked['objectOwner'], $label);
		}
	}//end testWithoutItsOwnSchemaOrOwnerTheGivenSchemaAndNoOwnerAreUsed()
}//end class
