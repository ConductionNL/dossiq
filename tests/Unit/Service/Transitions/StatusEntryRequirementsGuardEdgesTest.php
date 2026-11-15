<?php

/**
 * StatusEntryRequirementsGuard: no target, and an expanded case type.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseType\CaseConditionEvaluator;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\StatusEntryRequirementsGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusEntryRequirementsGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 * @uses \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard
 */
class StatusEntryRequirementsGuardEdgesTest extends TestCase {

	/**
	 * Without a target status the case type is not even read.
	 *
	 * @return void
	 */
	public function testWithoutATargetStatusItPassesUnread(): void {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->expects($this->never())->method('read');

		$guard = new StatusEntryRequirementsGuard(
			caseTypes: $caseTypes,
			documents: new RequiredDocumentGuard(l10n: new SourceL10n()),
			conditions: $this->createMock(CaseConditionEvaluator::class)
		);

		$this->assertTrue($guard->evaluate(['toStatus' => ' '], ['caseType' => 'ct-1'], 'jan')->passed);
	}//end testWithoutATargetStatusItPassesUnread()

	/**
	 * An expanded case type is read by its id, and its requirement is enforced.
	 *
	 * @return void
	 */
	public function testAnExpandedCaseTypeIsReadByItsId(): void {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->expects($this->exactly(2))
			->method('read')
			->with('ct-7')
			->willReturn(['entryRequirements' => ['st-besluit' => [['documentType' => 'advies']]]]);

		$guard = new StatusEntryRequirementsGuard(
			caseTypes: $caseTypes,
			documents: new RequiredDocumentGuard(l10n: new SourceL10n()),
			conditions: $this->createMock(CaseConditionEvaluator::class)
		);

		$refused = $guard->evaluate(['toStatus' => 'st-besluit'], ['caseType' => ['id' => 'ct-7', 'title' => 'Bouw']], 'jan');
		$passed = $guard->evaluate(
			['toStatus' => 'st-besluit'],
			['caseType' => ['uuid' => 'ct-7'], 'documents' => [['documentType' => 'advies']]],
			'jan'
		);

		$this->assertFalse($refused->passed);
		$this->assertSame('Required document missing: advies', $refused->failureMessage);
		$this->assertTrue($passed->passed);
	}//end testAnExpandedCaseTypeIsReadByItsId()
}//end class
