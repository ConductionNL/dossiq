<?php

/**
 * Unit test for RoleLinkRequest.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\RoleLinkRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkRequest
 */
class RoleLinkRequestTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$targetId = 'targetId-value';
		$payload = ['payload' => 2];

		$value = new RoleLinkRequest(
			caseId: $caseId,
			targetId: $targetId,
			payload: $payload,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $targetId, actual: $value->targetId);
		$this->assertSame(expected: $payload, actual: $value->payload);
	}//end testEveryPropertyReadsBack()
}//end class
