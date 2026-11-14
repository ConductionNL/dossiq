<?php

/**
 * Unit test for ObjectionLink.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\ObjectionLink;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\ObjectionLink
 */
class ObjectionLinkTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectionCaseId = 'objectionCaseId-value';
		$contestedDecisionId = 'contestedDecisionId-value';

		$value = new ObjectionLink(
			objectionCaseId: $objectionCaseId,
			contestedDecisionId: $contestedDecisionId,
		);

		$this->assertSame(expected: $objectionCaseId, actual: $value->objectionCaseId);
		$this->assertSame(expected: $contestedDecisionId, actual: $value->contestedDecisionId);
	}//end testEveryPropertyReadsBack()
}//end class
