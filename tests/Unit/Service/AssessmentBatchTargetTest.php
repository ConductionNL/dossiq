<?php

/**
 * Tests for AssessmentBatchTarget: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\AssessmentBatchTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for AssessmentBatchTarget.
 *
 * @covers \OCA\Dossiq\Service\AssessmentBatchTarget
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AssessmentBatchTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$register = new stdClass();
		$schema = new stdClass();
		$subject = new AssessmentBatchTarget(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			userId: 'userId-value',
			caseId: 'caseId-value'
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: $register, actual: $subject->register);
		self::assertSame(expected: $schema, actual: $subject->schema);
		self::assertSame(expected: 'userId-value', actual: $subject->userId);
		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
	}//end testPropertiesReadBack()

	/**
	 * A save goes to the object service in the batch's register and schema, with the uuid it replaces, and says what it did.
	 *
	 * @return void
	 */
	public function testASaveNamesTheBatchsRegisterSchemaAndUuid(): void {
		$objectService = new class {
			/**
			 * The calls made.
			 *
			 * @var array<int, array{object: array<string, mixed>, options: ObjectSaveOptions}>
			 */
			public array $calls = [];

			/**
			 * Record a save and answer its object.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options The options.
			 *
			 * @return array<string, mixed> The object saved.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): array {
				$this->calls[] = ['object' => $object, 'options' => $options];
				return $object + ['id' => 'a-1'];
			}//end saveObject()
		};
		$target = new AssessmentBatchTarget(objectService: $objectService, register: 'dossiq', schema: 'wooDocumentAssessment', userId: 'u', caseId: 'c');

		self::assertSame(
			expected: ['saved' => ['documentRef' => 'doc-1', 'id' => 'a-1'], 'created' => 1, 'documentRef' => 'doc-1'],
			actual: $target->save(assessment: ['documentRef' => 'doc-1'], uuid: null)
		);
		self::assertSame(expected: 0, actual: $target->save(assessment: ['documentRef' => 'doc-2'], uuid: 'a-2')['created']);

		self::assertSame(expected: 'dossiq', actual: $objectService->calls[0]['options']->target->register);
		self::assertSame(expected: 'wooDocumentAssessment', actual: $objectService->calls[0]['options']->target->schema);
		self::assertNull(actual: $objectService->calls[0]['options']->uuid);
		self::assertSame(expected: 'a-2', actual: $objectService->calls[1]['options']->uuid);
		self::assertSame(expected: ['documentRef' => 'doc-2'], actual: $objectService->calls[1]['object']);
	}//end testASaveNamesTheBatchsRegisterSchemaAndUuid()
}//end class
