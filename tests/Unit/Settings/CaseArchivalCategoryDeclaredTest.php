<?php

/**
 * The case declares where OpenRegister reads its selectielijst category.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Decision 127: `case.selectionListClass` is the archival category, read by
 * OpenRegister through `x-openregister-archival.categoryProperty`.
 *
 * @coversNothing
 */
class CaseArchivalCategoryDeclaredTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @var RealSchemaValidator
	 */
	private RealSchemaValidator $register;

	/**
	 * Load the real register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new RealSchemaValidator();
	}//end setUp()

	/**
	 * The archival block names the case field, beside its retention.
	 *
	 * @return void
	 */
	public function testTheArchivalBlockNamesTheCategoryProperty(): void {
		$archival = ($this->register->schemas['case']['x-openregister-archival'] ?? []);

		$this->assertSame('selectionListClass', ($archival['categoryProperty'] ?? null));
		$this->assertArrayHasKey('retention', $archival);
		$this->assertArrayNotHasKey(
			'category',
			$archival,
			'one schema-wide category would be wrong for most cases; the class lives on the result type'
		);
	}//end testTheArchivalBlockNamesTheCategoryProperty()

	/**
	 * The field the block names exists on the case, and takes a result type's class.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheResultTypesClass(): void {
		$case = $this->register->schemas['case'];
		$resultType = $this->register->schemas['resultType'];

		$this->assertArrayHasKey('selectionListClass', $case['properties']);
		$this->assertSame(
			$resultType['properties']['selectionListClass']['type'],
			$case['properties']['selectionListClass']['type']
		);

		$this->assertSame(
			[],
			$this->register->errors(
				slug: 'case',
				payload: ['selectionListClass' => 'https://selectielijst.openzaak.nl/api/v1/resultaten/8af64c99'],
				creating: false
			)
		);
		$this->assertNotSame(
			[],
			$this->register->errors(slug: 'case', payload: ['selectionListClass' => 'not a uri'], creating: false)
		);
	}//end testTheCaseCarriesTheResultTypesClass()
}//end class
