<?php

/**
 * Tests for PartnerRow: every property reads back what was passed in.
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
 * @spec openspec/specs/partner-organisations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\PartnerRow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PartnerRow.
 *
 * @spec openspec/specs/partner-organisations/spec.md
 */
class PartnerRowTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new PartnerRow(
			row: ['row' => 1],
			partnerUuid: 'partnerUuid-value',
			slug: 'slug-value'
		);

		self::assertSame(expected: ['row' => 1], actual: $subject->row);
		self::assertSame(expected: 'partnerUuid-value', actual: $subject->partnerUuid);
		self::assertSame(expected: 'slug-value', actual: $subject->slug);
	}//end testPropertiesReadBack()
}//end class
