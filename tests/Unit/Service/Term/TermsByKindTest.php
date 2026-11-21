<?php

/**
 * TermsByKind unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
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

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermsByKind;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;

/**
 * The terms of a case read by their kind.
 *
 * @covers \OCA\Dossiq\Service\Term\TermsByKind
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class TermsByKindTest extends TestCase {

	/**
	 * The first term of a kind is answered; a kind the case does not carry is null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheFirstTermOfAKindIsAnswered(): void {
		$terms = [
			['id' => 'p-1', 'kind' => TermKind::PHASE],
			['id' => 's-1', 'kind' => TermKind::STATUTORY],
			['id' => 's-2', 'kind' => TermKind::STATUTORY],
			['id' => 'none'],
		];

		$this->assertSame(
			expected: ['id' => 's-1', 'kind' => TermKind::STATUTORY],
			actual: (new TermsByKind())->first(terms: $terms, kind: TermKind::STATUTORY)
		);
		$this->assertNull(actual: (new TermsByKind())->first(terms: $terms, kind: TermKind::PLANNED));
	}//end testTheFirstTermOfAKindIsAnswered()

	/**
	 * A kind is overdue when one of its terms says so, and only then.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAKindIsOverdueWhenOneOfItsTermsIs(): void {
		$flags = (new TermsByKind())->overdueFlags(
			terms: [
				['kind' => TermKind::PHASE, 'overdue' => false],
				['kind' => TermKind::PHASE, 'overdue' => true],
				['kind' => TermKind::PLANNED, 'overdue' => 'yes'],
				['kind' => TermKind::STATUTORY],
			]
		);

		$this->assertSame(
			expected: ['phaseOverdue' => true, 'plannedOverdue' => false, 'statutoryOverdue' => false],
			actual: $flags
		);
	}//end testAKindIsOverdueWhenOneOfItsTermsIs()
}//end class
