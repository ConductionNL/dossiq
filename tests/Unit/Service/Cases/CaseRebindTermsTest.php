<?php

/**
 * The rebind re-arms terms under the target's SLUG, never its uuid.
 *
 * Term definitions are keyed by the case type slug. A uuid matches none of
 * them, so a re-arm handed one does nothing and reports a clean zero. This
 * test holds the slug lookup and the re-arm together now that they live in
 * one class.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseRebindTerms;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\Termijn\TermRearm;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Cases\CaseRebindTerms
 */
class CaseRebindTermsTest extends TestCase {
	/**
	 * The uuid the rebind holds is turned into the slug the definitions use.
	 *
	 * @return void
	 */
	public function testTheTermsAreReArmedUnderTheTargetsSlug(): void {
		$slugs = $this->createMock(CaseTypeSlugResolver::class);
		$slugs->expects($this->once())
			->method('toSlug')
			->with('6f1c2a0e-uuid-of-kapvergunning')
			->willReturn('kapvergunning');

		$terms = $this->createMock(TermRearm::class);
		$terms->expects($this->once())
			->method('forDefinition')
			->with('case-1', 'kapvergunning', 'Wrong case type at intake')
			->willReturn(['rearmed' => 2, 'kept' => 0, 'note' => '']);

		$rearm = new CaseRebindTerms(terms: $terms, slugs: $slugs);

		$this->assertSame(
			['rearmed' => 2, 'kept' => 0, 'note' => ''],
			$rearm->rearm(caseId: 'case-1', targetCaseTypeId: '6f1c2a0e-uuid-of-kapvergunning', reason: 'Wrong case type at intake')
		);
	}//end testTheTermsAreReArmedUnderTheTargetsSlug()
}//end class
