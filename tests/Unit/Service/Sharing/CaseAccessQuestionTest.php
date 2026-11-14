<?php

/**
 * Tests for CaseAccessQuestion: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
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
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\CaseAccessQuestion;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for CaseAccessQuestion.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class CaseAccessQuestionTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$subject = new CaseAccessQuestion(
			objectService: $objectService,
			register: 'register-value',
			caseSchema: 'caseSchema-value',
			caseId: 'caseId-value',
			userId: 'userId-value'
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: 'register-value', actual: $subject->register);
		self::assertSame(expected: 'caseSchema-value', actual: $subject->caseSchema);
		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'userId-value', actual: $subject->userId);
	}//end testPropertiesReadBack()
}//end class
