<?php

/**
 * Reading an advisory panel's members as user ids, refusing a malformed panel.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\ReadsPanelMembers;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\ReadsPanelMembers
 */
class ReadsPanelMembersTest extends TestCase {

	/**
	 * A reader exposing the trait's private helpers.
	 *
	 * @return object
	 */
	private function reader(): object {
		return new class {
			use ReadsPanelMembers;

			/**
			 * The members, or none for a malformed panel.
			 *
			 * @param array<int, mixed> $panel The panel.
			 *
			 * @return array<int, string>
			 */
			public function members(array $panel): array {
				return $this->panelMembers(panel: $panel);
			}

			/**
			 * The members, or an exception.
			 *
			 * @param array<int, mixed> $panel The panel.
			 *
			 * @return array<int, string>
			 */
			public function required(array $panel): array {
				return $this->requirePanelMembers(panel: $panel);
			}
		};
	}//end reader()

	public function testScalarMembersAreTrimmedIncludingUnicodeSpaceAndBlanksDropped(): void {
		$members = $this->reader()->members(panel: [' alice ', "\u{00A0}bob\u{2003}", '', null, '   ', 42]);

		self::assertSame(['alice', 'bob', '42'], $members);
	}//end testScalarMembersAreTrimmedIncludingUnicodeSpaceAndBlanksDropped()

	public function testAPanelWithANestedEntryReadsAsEmpty(): void {
		self::assertSame([], $this->reader()->members(panel: ['alice', ['uid' => 'bob']]));
		self::assertSame([], $this->reader()->members(panel: ['alice', new \stdClass()]));
	}//end testAPanelWithANestedEntryReadsAsEmpty()

	public function testRequiredMembersAreAnsweredWhenPresent(): void {
		self::assertSame(['alice', 'carol'], $this->reader()->required(panel: ['alice', null, ' carol']));
	}//end testRequiredMembersAreAnsweredWhenPresent()

	public function testRequiringAMalformedPanelNamesTheShapeProblem(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Panel members must be user ids, not lists or objects');
		$this->reader()->required(panel: ['alice', ['bob']]);
	}//end testRequiringAMalformedPanelNamesTheShapeProblem()

	public function testRequiringAnEmptyPanelRefusesDeliberation(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Panel must be set before deliberation can start');
		$this->reader()->required(panel: [' ', null]);
	}//end testRequiringAnEmptyPanelRefusesDeliberation()
}//end class
