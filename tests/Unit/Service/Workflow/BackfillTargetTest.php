<?php

/**
 * BackfillTarget Unit Tests
 *
 * A backfill target saves into the named schema of its configured register:
 * a new object without a uuid, a replaced one under its uuid, answering what
 * the object service answered, and letting a refusal reach the caller.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Workflow\BackfillTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for BackfillTarget.
 *
 * @covers \OCA\Dossiq\Service\Workflow\BackfillTarget
 * @uses   \OCA\Dossiq\Service\Workflow\BackfillSchemas
 */
final class BackfillTargetTest extends TestCase {

	/**
	 * A target over a recording store and the configured register `dossiq`.
	 *
	 * @param object $store The object store.
	 *
	 * @return BackfillTarget
	 */
	private function target(object $store): BackfillTarget {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ($key . '-schema')
		);
		return new BackfillTarget(objectService: $store, settings: $settings);
	}//end target()

	/**
	 * An object store that records its saves, or refuses them.
	 *
	 * @param bool $refuses Whether every save is refused.
	 *
	 * @return object
	 */
	private function store(bool $refuses = false): object {
		return new class($refuses) {
			/**
			 * The saves, as [object, register, schema, uuid].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param bool $refuses Whether every save is refused.
			 */
			public function __construct(private bool $refuses) {
			}

			/**
			 * Record a save and answer the object with an id, or refuse it.
			 *
			 * @param array             $object  The object.
			 * @param ObjectSaveOptions $options Where it goes.
			 *
			 * @return array
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): array {
				if ($this->refuses === true) {
					throw new RuntimeException('store down');
				}

				$this->saves[] = [$object, $options->target->register, $options->target->schema, $options->uuid];
				return $object + ['id' => ($options->uuid ?? 'new-1')];
			}
		};
	}//end store()

	/**
	 * A new object is saved without a uuid into the named schema, and the store's answer comes back.
	 *
	 * @return void
	 */
	public function testANewObjectIsSavedIntoTheNamedSchema(): void {
		$store = $this->store();
		$target = $this->target(store: $store);

		$saved = $target->save(schema: $target->schemas->template, object: ['title' => 'Basis']);

		$this->assertSame(expected: ['title' => 'Basis', 'id' => 'new-1'], actual: $saved);
		$this->assertSame(expected: [[['title' => 'Basis'], 'dossiq', 'workflow_template_schema-schema', null]], actual: $store->saves);
	}//end testANewObjectIsSavedIntoTheNamedSchema()

	/**
	 * An existing object is replaced under its uuid.
	 *
	 * @return void
	 */
	public function testAnExistingObjectIsReplacedUnderItsUuid(): void {
		$store = $this->store();
		$target = $this->target(store: $store);

		$target->save(schema: $target->schemas->cases, object: ['workflowVersion' => 1], uuid: 'case-1');

		$this->assertSame(expected: [[['workflowVersion' => 1], 'dossiq', 'case', 'case-1']], actual: $store->saves);
	}//end testAnExistingObjectIsReplacedUnderItsUuid()

	/**
	 * A refused save reaches the caller.
	 *
	 * @return void
	 */
	public function testARefusedSaveReachesTheCaller(): void {
		$target = $this->target(store: $this->store(refuses: true));

		$this->expectException(exception: RuntimeException::class);
		$target->save(schema: $target->schemas->caseType, object: ['x' => 1]);
	}//end testARefusedSaveReachesTheCaller()
}//end class
