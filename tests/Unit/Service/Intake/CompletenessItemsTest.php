<?php

/**
 * CompletenessItems unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CompletenessItems;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use PHPUnit\Framework\TestCase;

/**
 * The completeness items a case type declares.
 *
 * @covers \OCA\Dossiq\Service\Intake\CompletenessItems
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CompletenessItemsTest extends TestCase {

	/**
	 * Items are trimmed with their usable options in a fixed order; malformed ones are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheDeclaredItemsAreReadCleaned(): void {
		$caseType = [
			IntakeRequirements::PROPERTY => [
				'completenessItems' => [
					['key' => ' plan ', 'label' => ' Bouwtekening ', 'evidence' => 'ok', 'documentType' => ' type-1 ', 'field' => ' '],
					['key' => 'x', 'label' => ''],
					'not an item',
					['key' => 'owner', 'label' => 'Eigenaar', 'field' => 'owner.name', 'documentTitleContains' => 7],
				],
			],
		];

		$this->assertSame(
			expected: [
				['key' => 'plan', 'label' => 'Bouwtekening', 'documentType' => 'type-1', 'evidence' => 'ok'],
				['key' => 'owner', 'label' => 'Eigenaar', 'field' => 'owner.name'],
			],
			actual: (new CompletenessItems())->declaredBy(caseType: $caseType)
		);
	}//end testTheDeclaredItemsAreReadCleaned()

	/**
	 * A case type without a list of items declares none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testACaseTypeWithoutAListDeclaresNothing(): void {
		$items = new CompletenessItems();

		$this->assertSame(expected: [], actual: $items->declaredBy(caseType: []));
		$this->assertSame(
			expected: [],
			actual: $items->declaredBy(caseType: [IntakeRequirements::PROPERTY => ['completenessItems' => 'plan']])
		);
	}//end testACaseTypeWithoutAListDeclaresNothing()
}//end class
