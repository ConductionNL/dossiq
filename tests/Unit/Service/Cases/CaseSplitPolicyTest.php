<?php

/**
 * The split declaration of a case type and the refusal of a selection it does not allow.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;


use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Which parts a case type lets a split divide, and the sentence a refused selection gets.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
 */
class CaseSplitPolicyTest extends TestCase {

	/**
	 * The policy under test.
	 *
	 * @var CaseSplitPolicy
	 */
	private CaseSplitPolicy $policy;

	/**
	 * Build the policy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policy = new CaseSplitPolicy();
	}//end setUp()

	/**
	 * A case type without a declaration, or none at all, allows every part.
	 *
	 * @return void
	 */
	public function testAnUndeclaredTypeAllowsEveryPart(): void {
		self::assertSame(['documents', 'parties', 'tasks'], $this->policy->allowedFor(caseType: null));
		self::assertSame(['documents', 'parties', 'tasks'], $this->policy->allowedFor(caseType: ['title' => 'Bezwaar']));
		self::assertSame(
			['documents', 'parties', 'tasks'],
			$this->policy->allowedFor(caseType: [CaseSplitPolicy::DECLARATION => 'documents'])
		);
	}//end testAnUndeclaredTypeAllowsEveryPart()

	/**
	 * A declaration is read in the stable part order, dropping what is not a part.
	 *
	 * @return void
	 */
	public function testADeclarationIsReadInTheStableOrder(): void {
		self::assertSame(
			['documents', 'tasks'],
			$this->policy->allowedFor(caseType: [CaseSplitPolicy::DECLARATION => ['tasks', 'notes', 'documents']])
		);
		self::assertSame([], $this->policy->allowedFor(caseType: [CaseSplitPolicy::DECLARATION => []]));
	}//end testADeclarationIsReadInTheStableOrder()

	/**
	 * A selection within the declaration may proceed.
	 *
	 * @return void
	 */
	public function testAnAllowedSelectionIsNotRefused(): void {
		$caseType = [CaseSplitPolicy::DECLARATION => ['documents', 'parties']];

		self::assertSame('', $this->policy->whyRefused(selected: ['parties', 'documents'], caseType: $caseType));
		self::assertSame('', $this->policy->whyRefused(selected: [], caseType: $caseType));
	}//end testAnAllowedSelectionIsNotRefused()

	/**
	 * A part that is not a part of a case is named before anything else.
	 *
	 * @return void
	 */
	public function testAnUnknownPartIsNamed(): void {
		self::assertSame(
			'A split divides documents, parties or tasks, and "notes" is none of those.',
			$this->policy->whyRefused(selected: ['tasks', 'notes'], caseType: [CaseSplitPolicy::DECLARATION => ['documents']])
		);
	}//end testAnUnknownPartIsNamed()

	/**
	 * A refusal names every refused part and what may still be divided.
	 *
	 * @return void
	 */
	public function testARefusalNamesWhatMayStillBeDivided(): void {
		self::assertSame(
			'This case type does not allow parties and tasks to be divided. It allows documents to be divided.',
			$this->policy->whyRefused(selected: ['parties', 'documents', 'tasks'], caseType: [CaseSplitPolicy::DECLARATION => ['documents']])
		);
	}//end testARefusalNamesWhatMayStillBeDivided()

	/**
	 * A type that allows nothing says so.
	 *
	 * @return void
	 */
	public function testATypeThatAllowsNothingSaysSo(): void {
		self::assertSame(
			'This case type does not allow documents to be divided. This case type allows no part of a case to be divided.',
			$this->policy->whyRefused(selected: ['documents'], caseType: [CaseSplitPolicy::DECLARATION => []])
		);
	}//end testATypeThatAllowsNothingSaysSo()
}//end class
