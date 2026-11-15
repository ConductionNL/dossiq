<?php

/**
 * Tests for ApplicantWrite: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\ApplicantWrite;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicantWrite.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class ApplicantWriteTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new ApplicantWrite(
			caseId: 'caseId-value',
			act: 'act-value',
			fields: ['fields' => 1],
			occurredAt: 'occurredAt-value',
			mandate: ['mandate' => 1]
		);

		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'act-value', actual: $subject->act);
		self::assertSame(expected: ['fields' => 1], actual: $subject->fields);
		self::assertSame(expected: 'occurredAt-value', actual: $subject->occurredAt);
		self::assertSame(expected: ['mandate' => 1], actual: $subject->mandate);
	}//end testPropertiesReadBack()
}//end class
