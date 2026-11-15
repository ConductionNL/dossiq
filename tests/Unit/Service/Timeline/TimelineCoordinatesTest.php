<?php

/**
 * Tests for TimelineCoordinates: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
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
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Service\Timeline\TimelineCoordinates;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for TimelineCoordinates.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
class TimelineCoordinatesTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$register = new stdClass();
		$schema = new stdClass();
		$subject = new TimelineCoordinates(
			objectService: $objectService,
			register: $register,
			schema: $schema
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: $register, actual: $subject->register);
		self::assertSame(expected: $schema, actual: $subject->schema);
	}//end testPropertiesReadBack()
}//end class
