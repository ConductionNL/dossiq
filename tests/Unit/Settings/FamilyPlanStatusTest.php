<?php

/**
 * A family plan says where it stands (being drawn up, adopted, evaluated),
 * and the Jeugdwet example case's plan is being drawn up, as DqSociaalDomein
 * draws it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude schema configuration and package example data; no requirement covers the plan status yet
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The family plan status.
 */
class FamilyPlanStatusTest extends TestCase {

	/**
	 * The status is optional, three values, each with a label.
	 *
	 * @return void
	 */
	public function testThePlanStatusIsAnOptionalLabelledEnum(): void {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/50-sociaal-domein.json'), true);
		$schema   = $register['components']['schemas']['gezinsplan'];
		$status   = $schema['properties']['status'];

		$this->assertSame(expected: ['draft', 'determined', 'evaluated'], actual: $status['enum']);
		$this->assertSame(expected: $status['enum'], actual: array_keys($status['x-enum-labels']));
		$this->assertNotContains(needle: 'status', haystack: $schema['required']);
	}//end testThePlanStatusIsAnOptionalLabelledEnum()

	/**
	 * The Jeugdwet example case has a plan, being drawn up, by its handler.
	 *
	 * @return void
	 */
	public function testTheExampleCasePlanIsBeingDrawnUp(): void {
		$dir  = __DIR__.'/../../../lib/Settings/packages/jeugdwet/examples/';
		$case = json_decode((string)file_get_contents($dir.'20-case.json'), true)['rows'][0];
		$file = json_decode((string)file_get_contents($dir.'15-family-plan.json'), true);
		$plan = $file['rows'][0];

		$this->assertSame(expected: 'gezinsplan', actual: $file['schema']['slug']);
		$this->assertSame(expected: $case['id'], actual: $plan['caseId']);
		$this->assertSame(expected: $case['assignee'], actual: $plan['preparedBy']);
		$this->assertSame(expected: 'draft', actual: $plan['status']);
	}//end testTheExampleCasePlanIsBeingDrawnUp()
}//end class
