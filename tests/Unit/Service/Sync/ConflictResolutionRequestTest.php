<?php

/**
 * Tests for ConflictResolutionRequest: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sync
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
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sync;

use OCA\Dossiq\Service\Sync\ConflictResolutionRequest;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for ConflictResolutionRequest.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
class ConflictResolutionRequestTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$subject = new ConflictResolutionRequest(
			objectService: $objectService,
			register: 'register-value',
			id: 'id-value',
			resolution: 'resolution-value',
			userId: 'userId-value'
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: 'register-value', actual: $subject->register);
		self::assertSame(expected: 'id-value', actual: $subject->id);
		self::assertSame(expected: 'resolution-value', actual: $subject->resolution);
		self::assertSame(expected: 'userId-value', actual: $subject->userId);
	}//end testPropertiesReadBack()
}//end class
