<?php

/**
 * Tests for TakeoverAsk: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\TakeoverAsk;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TakeoverAsk.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
class TakeoverAskTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new TakeoverAsk(
			caseId: 'caseId-value',
			requestedBy: 'requestedBy-value',
			holder: 'holder-value',
			unit: 'unit-value',
			reason: 'reason-value'
		);

		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'requestedBy-value', actual: $subject->requestedBy);
		self::assertSame(expected: 'holder-value', actual: $subject->holder);
		self::assertSame(expected: 'unit-value', actual: $subject->unit);
		self::assertSame(expected: 'reason-value', actual: $subject->reason);
	}//end testPropertiesReadBack()
}//end class
