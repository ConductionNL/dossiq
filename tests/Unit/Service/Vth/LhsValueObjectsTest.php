<?php

/**
 * Tests for the LHS question and override value objects.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;


use OCA\Dossiq\Service\Vth\LhsOverride;
use OCA\Dossiq\Service\Vth\LhsQuestion;
use PHPUnit\Framework\TestCase;

/**
 * A question names its matrix cell, and an override names its authority.
 *
 * @covers \OCA\Dossiq\Service\Vth\LhsQuestion
 * @covers \OCA\Dossiq\Service\Vth\LhsOverride
 *
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
class LhsValueObjectsTest extends TestCase {

	/**
	 * The cell key joins severity, behaviour and actor type, in that order.
	 *
	 * @return void
	 */
	public function testTheCellKeyJoinsTheThreeAxes(): void {
		$question = new LhsQuestion(caseId: 'case-1', severity: 'B', behaviour: '3', actorType: 'bedrijf', inspection: null);

		$this->assertSame('B:3:bedrijf', $question->cellKey());
	}//end testTheCellKeyJoinsTheThreeAxes()

	/**
	 * Only a manager overrides as manager; every other role overrides as inspector.
	 *
	 * @return void
	 */
	public function testOnlyAManagerHasManagerAuthority(): void {
		$this->assertSame('manager', (new LhsOverride(intervention: 'B2', justification: 'x', userRole: 'manager'))->authority());
		$this->assertSame('inspector', (new LhsOverride(intervention: 'B2', justification: 'x', userRole: 'inspector'))->authority());
		$this->assertSame('inspector', (new LhsOverride(intervention: 'B2', justification: 'x', userRole: 'Manager'))->authority());
		$this->assertSame('inspector', (new LhsOverride(intervention: 'B2', justification: 'x', userRole: ''))->authority());
	}//end testOnlyAManagerHasManagerAuthority()

}//end class
