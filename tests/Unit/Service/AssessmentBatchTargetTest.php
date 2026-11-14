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
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for AssessmentBatchTarget.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
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
}//end class
