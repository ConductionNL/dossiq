<?php

/**
 * Unit tests for ConsultationStatusRules.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\Consultation\ConsultationStatusRules;
use OCA\Dossiq\Service\Obligations\ObligationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The status graph, the advice types, the blocking rule and the mirroring obligation.
 *
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationStatusRules
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ConsultationStatusRulesTest extends TestCase {

	/**
	 * Every move the graph declares passes; a move it does not declare is refused.
	 *
	 * @return void
	 */
	public function testTheGraphAllowsOnlyItsDeclaredMoves(): void {
		$rules = new ConsultationStatusRules();

		$rules->assertMove(current: 'open', newStatus: 'received');
		$rules->assertMove(current: 'received', newStatus: 'in_handling');
		$rules->assertMove(current: 'in_handling', newStatus: 'advice_uitgebracht');
		$rules->assertMove(current: 'advice_uitgebracht', newStatus: 'closed');
		$rules->assertMove(current: 'in_handling', newStatus: 'withdrawn');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid status transition: withdrawn -> open');

		$rules->assertMove(current: 'withdrawn', newStatus: 'open');
	}//end testTheGraphAllowsOnlyItsDeclaredMoves()

	/**
	 * An absent or unknown stored status does not constrain the move.
	 *
	 * @return void
	 */
	public function testAnUnknownStoredStatusDoesNotConstrain(): void {
		$rules = new ConsultationStatusRules();

		$rules->assertMove(current: '', newStatus: 'closed');
		$rules->assertMove(current: 'legacy', newStatus: 'open');

		$this->addToAssertionCount(count: 2);
	}//end testAnUnknownStoredStatusDoesNotConstrain()

	/**
	 * A status that is not declared is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsRefused(): void {
		$rules = new ConsultationStatusRules();
		$rules->assertKnownStatus(newStatus: 'withdrawn');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid status: ongeldig');

		$rules->assertKnownStatus(newStatus: 'ongeldig');
	}//end testAnUnknownStatusIsRefused()

	/**
	 * A declared advice type is returned; an absent one is refused.
	 *
	 * @return void
	 */
	public function testTheAdviceTypeMustBeDeclared(): void {
		$rules = new ConsultationStatusRules();
		$this->assertSame(expected: 'positief_with_terms', actual: $rules->adviceOf(response: ['advice' => 'positief_with_terms']));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid advice type: ');

		$rules->adviceOf(response: []);
	}//end testTheAdviceTypeMustBeDeclared()

	/**
	 * Only a mandatory request that is not answered, closed or withdrawn blocks.
	 *
	 * @return void
	 */
	public function testOnlyAnUnreleasedMandatoryRequestBlocks(): void {
		$rules = new ConsultationStatusRules();

		$this->assertTrue(condition: $rules->blocks(consultation: ['mandatory' => true, 'status' => 'in_handling']));
		$this->assertFalse(condition: $rules->blocks(consultation: ['mandatory' => true, 'status' => 'advice_uitgebracht']));
		$this->assertFalse(condition: $rules->blocks(consultation: ['mandatory' => true, 'status' => 'closed']));
		$this->assertFalse(condition: $rules->blocks(consultation: ['mandatory' => 1, 'status' => 'open']));
	}//end testOnlyAnUnreleasedMandatoryRequestBlocks()

	/**
	 * A mandatory request places an advice obligation with its due date; an optional one places none.
	 *
	 * @return void
	 */
	public function testAMandatoryRequestIsMirroredByAnObligation(): void {
		$rules = new ConsultationStatusRules();

		$this->assertNull(actual: $rules->mirroringObligation(consultationId: 'c1', data: ['parentCase' => 'case-1']));

		$this->assertSame(
			expected: [
				'case' => 'case-1',
				'kind' => ObligationService::KIND_ADVICE,
				'title' => 'Advice request',
				'placedOn' => '',
				'placedOnGroup' => 'Brandweer',
				'dueAt' => '2026-07-01',
				'source' => 'c1',
			],
			actual: $rules->mirroringObligation(
				consultationId: 'c1',
				data: [
					'mandatory' => true,
					'parentCase' => 'case-1',
					'adviceAuthority' => 'Brandweer',
					'latestResponseDate' => '2026-07-01T12:00:00+01:00',
				],
			),
		);
	}//end testAMandatoryRequestIsMirroredByAnObligation()

}//end class
