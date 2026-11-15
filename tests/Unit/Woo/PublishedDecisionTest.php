<?php

/**
 * Tests for PublishedDecision: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Woo\PublishedDecision;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublishedDecision.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */
class PublishedDecisionTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new PublishedDecision(
			case: ['case' => 1],
			caseId: 'caseId-value',
			publicationId: 'publicationId-value'
		);

		self::assertSame(expected: ['case' => 1], actual: $subject->case);
		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'publicationId-value', actual: $subject->publicationId);
	}//end testPropertiesReadBack()
}//end class
