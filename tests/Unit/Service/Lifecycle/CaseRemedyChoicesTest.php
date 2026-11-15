<?php

/**
 * CaseRemedyChoices tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Lifecycle\CaseRemedyChoices;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The remedy menu entries follow the case type's declaration and the decision being made known.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseRemedyChoices
 */
class CaseRemedyChoicesTest extends TestCase {

	/**
	 * Build the choices over one declaration.
	 *
	 * @param array<string, mixed> $declaration The normalised remedy declaration.
	 *
	 * @return CaseRemedyChoices
	 */
	private function choices(array $declaration): CaseRemedyChoices {
		$remedy = $this->createMock(CaseRemedy::class);
		$remedy->method('declarationOf')->willReturn(
			$declaration + ['kind' => 'bezwaar', 'termDays' => 42, 'body' => '', 'enabled' => true, 'caseType' => 'ct-bezwaar', 'id' => '', 'label' => '', 'description' => '']
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseRemedyChoices(remedy: $remedy, l10n: $l10n);
	}//end choices()

	/**
	 * Before a decision is made known the objection is listed but not available, with the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	public function testTheObjectionWaitsForADecisionMadeKnown(): void {
		$entries = $this->choices(['id' => 'objection', 'label' => 'Bezwaar registreren', 'description' => 'Pas na een bekendgemaakt besluit.'])
			->forCase(caseId: 'case-1', case: []);

		self::assertSame(
			[['id' => 'objection', 'label' => 'Bezwaar registreren', 'description' => 'Pas na een bekendgemaakt besluit.', 'available' => false, 'reason' => 'Only after a decision has been made known.']],
			$entries
		);
	}//end testTheObjectionWaitsForADecisionMadeKnown()

	/**
	 * Once the decision went out the remedy is available; without an id the kind is the id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	public function testAfterTheDecisionWentOutTheRemedyIsAvailable(): void {
		$entries = $this->choices([])->forCase(caseId: 'case-1', case: ['decisionSentOn' => '2026-10-20']);

		self::assertTrue($entries[0]['available']);
		self::assertNull($entries[0]['reason']);
		self::assertSame('bezwaar', $entries[0]['id']);
		self::assertSame('Register a remedy', $entries[0]['label']);
	}//end testAfterTheDecisionWentOutTheRemedyIsAvailable()

	/**
	 * A case type without a remedy case type, or with the remedy switched off, lists none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/remedy-lodging/spec.md#requirement-the-lifecycle-menu-offers-the-remedies-the-case-type-declares
	 */
	public function testNoDeclaredRemedyListsNone(): void {
		self::assertSame([], $this->choices(['caseType' => ''])->forCase(caseId: 'case-1', case: []));
		self::assertSame([], $this->choices(['enabled' => false])->forCase(caseId: 'case-1', case: []));
	}//end testNoDeclaredRemedyListsNone()
}//end class
