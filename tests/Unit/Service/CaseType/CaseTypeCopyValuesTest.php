<?php

/**
 * Case type copy value objects unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/changes/zaaktype-copy/tasks.md#T13
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeChildCopy;
use OCA\Dossiq\Service\CaseType\CaseTypeCopyTarget;
use OCA\Dossiq\Service\CaseType\CaseTypeDerivation;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * The values a case type copy passes between its steps.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeCopyTarget
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeDerivation
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeChildCopy
 */
class CaseTypeCopyValuesTest extends TestCase {

	/**
	 * A target on another schema keeps its object service and register.
	 *
	 * @return void
	 */
	public function testATargetOnAnotherSchemaKeepsItsServiceAndRegister(): void {
		$objects = new \stdClass();
		$target = (new CaseTypeCopyTarget(objectService: $objects, register: 'dossiq', schema: 'caseType'))->forSchema(schema: 'statusType');

		$this->assertSame($objects, $target->objectService);
		$this->assertSame('dossiq', $target->register);
		$this->assertSame('statusType', $target->schema);
	}//end testATargetOnAnotherSchemaKeepsItsServiceAndRegister()

	/**
	 * A child copy on another schema keeps its key and the new case type id, and leaves the original alone.
	 *
	 * @return void
	 */
	public function testAChildCopyOnAnotherSchemaKeepsItsKeyAndNewId(): void {
		$target = new CaseTypeCopyTarget(objectService: new \stdClass(), register: 'dossiq', schema: 'caseType');
		$copy = new CaseTypeChildCopy(target: $target, configKey: 'role_type_schema', newCaseTypeId: 'ct-2');
		$moved = $copy->forSchema(schema: 'roleType');

		$this->assertSame('roleType', $moved->target->schema);
		$this->assertSame('role_type_schema', $moved->configKey);
		$this->assertSame('ct-2', $moved->newCaseTypeId);
		$this->assertSame('caseType', $copy->target->schema);
	}//end testAChildCopyOnAnotherSchemaKeepsItsKeyAndNewId()

	/**
	 * A derivation holds its source and its kind.
	 *
	 * @return void
	 */
	public function testADerivationHoldsItsSourceAndKind(): void {
		$target = new CaseTypeCopyTarget(objectService: new \stdClass(), register: 'dossiq', schema: 'caseType');
		$derivation = new CaseTypeDerivation(target: $target, sourceId: 'ct-1', asVersion: true);

		$this->assertSame($target, $derivation->target);
		$this->assertSame('ct-1', $derivation->sourceId);
		$this->assertTrue($derivation->asVersion);
	}//end testADerivationHoldsItsSourceAndKind()

	/**
	 * A target saves into its own register and schema and answers what the store answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testATargetSavesIntoItsRegisterAndSchema(): void {
		$objects = $this->recordingStore();
		$target = new CaseTypeCopyTarget(objectService: $objects, register: 'dossiq', schema: 'statusType');

		$saved = $target->save(payload: ['name' => 'Ontvangen']);

		$this->assertSame(expected: ['saved' => ['name' => 'Ontvangen']], actual: $saved);
		$this->assertSame(expected: [['save', 'dossiq', 'statusType']], actual: $objects->calls);
	}//end testATargetSavesIntoItsRegisterAndSchema()

	/**
	 * A target finds by id in its own register and schema, and a refusal reaches the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testATargetFindsInItsRegisterAndSchemaAndLetsARefusalThrough(): void {
		$objects = $this->recordingStore();
		$target = new CaseTypeCopyTarget(objectService: $objects, register: 'dossiq', schema: 'caseType');

		$this->assertSame(expected: ['id' => 'ct-1'], actual: $target->find(id: 'ct-1'));
		$this->assertSame(expected: [['find', 'ct-1', 'dossiq', 'caseType']], actual: $objects->calls);

		$this->expectException(exception: \RuntimeException::class);
		$target->find(id: 'refused');
	}//end testATargetFindsInItsRegisterAndSchemaAndLetsARefusalThrough()

	/**
	 * An object store that records its calls.
	 *
	 * @return object
	 */
	private function recordingStore(): object {
		return new class {
			/**
			 * The calls, in order.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record a save and answer the payload wrapped.
			 *
			 * @param array             $object  The object.
			 * @param ObjectSaveOptions $options Where it goes.
			 *
			 * @return array
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): array {
				$this->calls[] = ['save', $options->target->register, $options->target->schema];
				return ['saved' => $object];
			}

			/**
			 * Record a find; refuse the id `refused`.
			 *
			 * @param string            $id      The id.
			 * @param ObjectFindOptions $options Where to look.
			 *
			 * @return array
			 */
			public function find(string $id, ObjectFindOptions $options): array {
				if ($id === 'refused') {
					throw new \RuntimeException('store down');
				}

				$this->calls[] = ['find', $id, $options->register, $options->schema];
				return ['id' => $id];
			}
		};
	}//end recordingStore()
}//end class
