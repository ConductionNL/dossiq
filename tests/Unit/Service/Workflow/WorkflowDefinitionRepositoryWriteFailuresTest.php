<?php

/**
 * WorkflowDefinitionRepositoryWriteFailuresTest.
 *
 * The write paths of the repository when the store is missing, a row cannot be
 * read, or OpenRegister refuses the write.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A write that cannot land answers null (or nothing) and is logged; it never
 * writes a fragment over a stored row.
 *
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository
 */
class WorkflowDefinitionRepositoryWriteFailuresTest extends TestCase {

	/**
	 * Error messages logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * A fake ObjectService.
	 *
	 * @param mixed $stored What `find()` answers with.
	 * @param bool $saveThrows Whether `saveObject()` refuses.
	 *
	 * @return object The fake.
	 */
	private function objectService(mixed $stored, bool $saveThrows = false): object {
		return new class($stored, $saveThrows) {
			/**
			 * The payloads handed to saveObject(), in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * @param mixed $stored What find() answers with.
			 * @param bool $saveThrows Whether saveObject() throws.
			 */
			public function __construct(private readonly mixed $stored, private readonly bool $saveThrows) {
			}

			/**
			 * @param string $id The uuid.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 *
			 * @return mixed The stored row.
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): mixed {
				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The row being updated.
			 *
			 * @return array<string, mixed> The written row.
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			): array {
				if ($this->saveThrows === true) {
					throw new \RuntimeException('The required properties (title) are missing');
				}

				$this->written[] = $object;

				return array_merge($object, ['id' => ($uuid ?? 'created')]);
			}
		};
	}

	/**
	 * Build the repository.
	 *
	 * @param object|null $objectService The fake, or null when OpenRegister is absent.
	 *
	 * @return WorkflowDefinitionRepository The repository.
	 */
	private function repository(?object $objectService): WorkflowDefinitionRepository {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '17',
				'workflow_template_schema' => '47',
				'case_type_schema' => '22',
				default => '',
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new WorkflowDefinitionRepository($settings, $logger);
	}

	/**
	 * Without OpenRegister nothing is saved and nothing is logged.
	 *
	 * @return void
	 */
	public function testSaveWithoutAStoreAnswersNull(): void {
		$this->assertNull($this->repository(null)->save(payload: ['title' => 'Route']));
		$this->assertNull($this->repository(null)->save(payload: ['title' => 'Route'], uuid: 'def-1'));
		$this->assertSame([], $this->errors);
	}

	/**
	 * A refused update answers null and logs the refusal.
	 *
	 * @return void
	 */
	public function testARefusedUpdateIsLoggedAndNull(): void {
		$objectService = $this->objectService(['id' => 'def-1', 'title' => 'Route', 'caseType' => 'ct-1'], saveThrows: true);

		$saved = $this->repository($objectService)->save(payload: ['isActive' => true], uuid: 'def-1');

		$this->assertNull($saved);
		$this->assertSame(['Dossiq: failed to save workflow definition'], $this->errors);
	}

	/**
	 * A refused create answers null and logs the refusal.
	 *
	 * @return void
	 */
	public function testARefusedCreateIsLoggedAndNull(): void {
		$objectService = $this->objectService(null, saveThrows: true);

		$this->assertNull($this->repository($objectService)->save(payload: ['title' => 'Route']));
		$this->assertSame(['Dossiq: failed to save workflow definition'], $this->errors);
	}

	/**
	 * A row the store answers with but that is not an array is unreadable:
	 * nothing is written and the refusal is logged.
	 *
	 * @return void
	 */
	public function testAnUncoercibleRowIsNotOverwritten(): void {
		$objectService = $this->objectService('not a row');

		$saved = $this->repository($objectService)->save(payload: ['isActive' => true], uuid: 'def-1');

		$this->assertNull($saved);
		$this->assertSame([], $objectService->written);
		$this->assertSame(['Dossiq: refusing to update a row that cannot be read'], $this->errors);
	}

	/**
	 * A read answers steps, transitions and nodePositions decoded; the update
	 * encodes them back to the JSON strings the schema stores, and leaves the
	 * other array properties alone.
	 *
	 * @return void
	 */
	public function testDecodedJsonPropertiesAreEncodedAgain(): void {
		$objectService = $this->objectService(
			[
				'id' => 'def-1',
				'title' => 'Route',
				'caseType' => 'ct-1',
				'steps' => [['slug' => 'gepland']],
				'transitions' => [],
				'nodePositions' => ['gepland' => ['x' => 1, 'y' => 2]],
				'tags' => ['a', 'b'],
			]
		);

		$saved = $this->repository($objectService)->save(payload: ['isActive' => true], uuid: 'def-1');

		$written = $objectService->written[0];
		$this->assertSame('[{"slug":"gepland"}]', $written['steps']);
		$this->assertSame('[]', $written['transitions']);
		$this->assertSame('{"gepland":{"x":1,"y":2}}', $written['nodePositions']);
		$this->assertSame(['a', 'b'], $written['tags']);
		$this->assertTrue($written['isActive']);
		$this->assertSame('def-1', $saved['id']);
	}

	/**
	 * A row exposing jsonSerialize() is read through it.
	 *
	 * @return void
	 */
	public function testASerialisableRowIsMergedOnto(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed> The row.
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'def-1'], 'id' => 'def-1', 'title' => 'Route', 'caseType' => 'ct-1'];
			}
		};
		$objectService = $this->objectService($entity);

		$this->repository($objectService)->save(payload: ['lifecycleStatus' => 'published'], uuid: 'def-1');

		$this->assertSame(
			['title' => 'Route', 'caseType' => 'ct-1', 'lifecycleStatus' => 'published'],
			$objectService->written[0]
		);
	}

	/**
	 * Without OpenRegister the pin is a silent no-op.
	 *
	 * @return void
	 */
	public function testThePinWithoutAStoreDoesNothing(): void {
		$this->repository(null)->pinWorkflowDefinition(caseTypeId: 'ct-1', definitionId: 'def-1');

		$this->assertSame([], $this->errors);
	}

	/**
	 * An unreadable case type is not pinned.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIsNotPinned(): void {
		$objectService = $this->objectService(null);

		$this->repository($objectService)->pinWorkflowDefinition(caseTypeId: 'ct-1', definitionId: 'def-1');

		$this->assertSame([], $objectService->written);
		$this->assertSame(['Dossiq: refusing to update a row that cannot be read'], $this->errors);
	}

	/**
	 * A refused pin is logged and swallowed: the caller falls back to the
	 * published and active definition.
	 *
	 * @return void
	 */
	public function testARefusedPinIsLoggedAndSwallowed(): void {
		$objectService = $this->objectService(['id' => 'ct-1', 'title' => 'Bouw'], saveThrows: true);

		$this->repository($objectService)->pinWorkflowDefinition(caseTypeId: 'ct-1', definitionId: 'def-1');

		$this->assertSame(['Dossiq: failed to pin caseType.workflowDefinition'], $this->errors);
	}
}
