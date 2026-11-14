<?php

/**
 * A case type that does not judge admissibility has nothing to be warned about at publication.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Intake\AdmissibilityJudgement
 * @uses   \OCA\Dossiq\Service\Support\StoredArray
 */
class AdmissibilityJudgementWarningsTest extends TestCase {

	/**
	 * A judgement switched off, or declared without `enabled: true`, warns of nothing and asks nobody.
	 *
	 * Without the early return the missing result type and the missing
	 * letter would both be reported for a case type that never judges.
	 *
	 * @return void
	 */
	public function testADisabledJudgementWarnsOfNothing(): void {
		$letters = $this->createMock(ApplicantMessage::class);
		$letters->expects(self::never())->method('declares');
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::never())->method('t');

		$judgement = new AdmissibilityJudgement(
			$this->createMock(CaseTypeResolver::class),
			$this->createMock(CaseStatusStore::class),
			$this->createMock(CaseEndingActs::class),
			$letters,
			$this->createMock(LoggerInterface::class),
			$l10n
		);

		self::assertSame([], $judgement->publicationWarnings(caseType: []));
		self::assertSame([], $judgement->publicationWarnings(caseType: ['admissibilityJudgement' => ['enabled' => false]]));
		self::assertSame([], $judgement->publicationWarnings(caseType: ['admissibilityJudgement' => ['enabled' => 'true']]));
	}//end testADisabledJudgementWarnsOfNothing()
}//end class
