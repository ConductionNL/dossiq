<?php

/**
 * Unit tests for ZgwRuleCall.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRuleCall;
use PHPUnit\Framework\TestCase;

/**
 * A rule call answers whether it is the one resource and action a rule is registered for.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRuleCall
 */
final class ZgwRuleCallTest extends TestCase {
	/**
	 * Only the exact resource and action pair matches; either half alone does not.
	 *
	 * @return void
	 */
	public function testOnlyTheExactResourceAndActionMatch(): void {
		$call = new ZgwRuleCall(resource: 'zaken', action: 'create', body: ['a' => 1], existingObject: null);

		$this->assertTrue($call->is(resource: 'zaken', action: 'create'));
		$this->assertFalse($call->is(resource: 'zaken', action: 'update'), 'same resource, other action');
		$this->assertFalse($call->is(resource: 'statussen', action: 'create'), 'same action, other resource');
		$this->assertFalse($call->is(resource: 'Zaken', action: 'create'), 'the match is case-sensitive');
		$this->assertFalse($call->is(resource: 'create', action: 'zaken'), 'the halves are not interchangeable');
	}//end testOnlyTheExactResourceAndActionMatch()
}//end class
