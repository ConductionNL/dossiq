<?php

/**
 * AutorisatieScopeFields unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\AutorisatieScopeFields;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Ac-003: the fields an autorisatie lacks for its component and scopes.
 *
 * @covers \OCA\Dossiq\Controller\Support\AutorisatieScopeFields
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AutorisatieScopeFieldsTest extends TestCase {

	/**
	 * The fields over a pass-through translator.
	 *
	 * @return AutorisatieScopeFields
	 */
	private function fields(): AutorisatieScopeFields {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new AutorisatieScopeFields(l10n: $l10n);
	}//end fields()

	/**
	 * A zaken scope on zrc requires the case type and the confidentiality, named by the entry's position.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAZakenScopeRequiresItsFieldsByPosition(): void {
		$params = $this->fields()->missingParams(
			index: 2,
			autorisatie: ['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'maxVertrouwelijkheidaanduiding' => '']
		);

		$this->assertSame(
			expected: [
				[
					'name' => 'autorisaties.2.zaaktype',
					'code' => 'required',
					'reason' => 'zaaktype is required when a scope related to zaken is specified.',
				],
				[
					'name' => 'autorisaties.2.maxVertrouwelijkheidaanduiding',
					'code' => 'required',
					'reason' => 'maxVertrouwelijkheidaanduiding is required when a scope related to zaken is specified.',
				],
			],
			actual: $params
		);
	}//end testAZakenScopeRequiresItsFieldsByPosition()

	/**
	 * A complete entry, an unrelated scope and an unknown component require nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testNothingIsRequiredWhereTheRuleDoesNotApply(): void {
		$fields = $this->fields();

		$this->assertSame(
			expected: [],
			actual: $fields->missingParams(index: 0, autorisatie: ['component' => 'brc', 'scopes' => ['besluiten.lezen'], 'besluittype' => 'bt'])
		);
		$this->assertSame(
			expected: [],
			actual: $fields->missingParams(index: 0, autorisatie: ['component' => 'drc', 'scopes' => ['notificaties.consumeren']])
		);
		$this->assertSame(
			expected: [],
			actual: $fields->missingParams(index: 0, autorisatie: ['component' => 'nrc', 'scopes' => ['zaken.lezen']])
		);
	}//end testNothingIsRequiredWhereTheRuleDoesNotApply()
}//end class
