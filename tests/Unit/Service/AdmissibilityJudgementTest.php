<?php

/**
 * Intake ends with a verdict, and an inadmissible one ends the case.
 *
 * 🔴 THE FAILURE THIS FILE GUARDS IS A CASE THAT STAYS OPEN. A verdict that
 * records "niet-ontvankelijk" and leaves the case in its intake phase reads
 * green everywhere: the verdict is on the case, the log line is written, and
 * nobody notices until a queue nobody works has four hundred entries in it. So
 * the assertions are about the CLOSE, the RESULT it carries and the JUDGE it
 * records, over the real close act rather than a double of it.
 *
 * 🔑 THE LETTER NEVER FAILS THE CLOSE. A case with no address on it still
 * closes, and the answer says the applicant was not told and why. The opposite
 * reading leaves exactly the dead phase the verdict exists to end, and leaves
 * it invisible, because the verdict is recorded and the case has not moved.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AdmissibilityJudgementTest extends TestCase {

	/**
	 * The result type an inadmissible aanvraag closes on.
	 *
	 * @var string
	 */
	private const NIET_ONTVANKELIJK = 'rt-niet-ontvankelijk';

	/**
	 * What the close act was asked to do, recorded by the double.
	 *
	 * @var array<string, mixed>
	 */
	private array $closed = [];

	/**
	 * What the case looked like when it was saved.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * What the applicant's letter was asked to send.
	 *
	 * @var array<string, mixed>
	 */
	private array $letter = [];

	/**
	 * A case type that judges admissibility, or one that does not.
	 *
	 * @param bool $enabled Whether the judgement is declared.
	 * @param string $result The result type for an inadmissible aanvraag.
	 *
	 * @return array<string, mixed> The case type row.
	 */
	private function caseType(bool $enabled = true, string $result = self::NIET_ONTVANKELIJK): array {
		return [
			'title' => 'Omgevingsvergunning',
			AdmissibilityJudgement::DECLARATION => [
				'enabled' => $enabled,
				'inadmissibleResultType' => $result,
			],
		];
	}//end caseType()

	/**
	 * The service under test, over doubles that record what they were asked.
	 *
	 * @param array<string, mixed> $caseType The case type the resolver answers with.
	 * @param bool $messageSent What the applicant's letter reports back.
	 *
	 * @return AdmissibilityJudgement The service.
	 */
	private function judgementOver(array $caseType, bool $messageSent = true): AdmissibilityJudgement {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn([
			'id' => 'case-1',
			'caseType' => 'ct-omgeving',
			'status' => 'st-intake',
		]);
		$store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->saved = $case;

				return $case;
			}
		);

		$endings = $this->createMock(originalClassName: CaseEndingActs::class);
		$endings->method('finish')->willReturnCallback(
			function (string $caseId, string $reason, string $resultTypeId, string $toStatus = ''): array {
				$this->closed = [
					'caseId' => $caseId,
					'reason' => $reason,
					'resultTypeId' => $resultTypeId,
				];

				return ['caseId' => $caseId, 'act' => 'finish', 'result' => $resultTypeId];
			}
		);

		$letters = $this->createMock(originalClassName: ApplicantMessage::class);
		$letters->method('declares')->willReturn($messageSent);
		$letters->method('send')->willReturnCallback(
			function (array $case, array $type, string $moment, string $template, array $context) use ($messageSent): array {
				$this->letter = ['moment' => $moment, 'template' => $template, 'context' => $context];

				$reason = 'no-address';
				if ($messageSent === true) {
					$reason = '';
				}

				return [
					'sent' => $messageSent,
					'reason' => $reason,
					'recipient' => '',
					'template' => $template,
					'sentAt' => '',
				];
			}
		);

		return new AdmissibilityJudgement(
			caseTypes: $resolver,
			store: $store,
			endings: $endings,
			moments: $this->createMock(originalClassName: CaseTypeAcknowledgement::class),
			letters: $letters,
			logger: new NullLogger(),
		);
	}//end judgementOver()

	/**
	 * A case type says nothing, so nothing is asked at intake.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingAsksForNoVerdict(): void {
		$service = $this->judgementOver(caseType: []);

		self::assertFalse(condition: $service->appliesTo(caseType: []));
		self::assertSame(
			expected: AdmissibilityJudgement::MOMENT,
			actual: $service->declarationFor(caseType: [])['moment'],
		);
	}//end testACaseTypeThatDeclaresNothingAsksForNoVerdict()

	/**
	 * The declaration reads back, whether it was stored as an array or encoded.
	 *
	 * @return void
	 */
	public function testAJsonEncodedDeclarationReadsTheSame(): void {
		$service = $this->judgementOver(caseType: []);
		$encoded = [
			AdmissibilityJudgement::DECLARATION => json_encode(
				['enabled' => true, 'inadmissibleResultType' => self::NIET_ONTVANKELIJK]
			),
		];

		$declaration = $service->declarationFor(caseType: $encoded);

		self::assertTrue(condition: $declaration['enabled']);
		self::assertSame(
			expected: self::NIET_ONTVANKELIJK,
			actual: $declaration['inadmissibleResultType'],
		);
	}//end testAJsonEncodedDeclarationReadsTheSame()

	/**
	 * An inadmissible verdict closes the case on the declared result.
	 *
	 * @return void
	 */
	public function testAnInadmissibleVerdictClosesTheCaseWithThatResult(): void {
		$service = $this->judgementOver(caseType: $this->caseType());

		$answer = $service->judge(
			caseId: 'case-1',
			verdict: AdmissibilityJudgement::INADMISSIBLE,
			reason: 'De aanvraag is te laat ingediend.',
			judgedBy: 'intake-1',
		);

		self::assertTrue(condition: $answer['closed']);
		// The ordinary close act, and the result the case type named. The
		// retention and the archival consequence come from that result type,
		// which is the whole reason this is a close and not a status of its own.
		self::assertSame(expected: 'case-1', actual: $this->closed['caseId']);
		self::assertSame(
			expected: self::NIET_ONTVANKELIJK,
			actual: $this->closed['resultTypeId'],
		);
		self::assertSame(
			expected: 'De aanvraag is te laat ingediend.',
			actual: $this->closed['reason'],
		);
	}//end testAnInadmissibleVerdictClosesTheCaseWithThatResult()

	/**
	 * The judge and the moment are recorded on the case, not only in a log.
	 *
	 * @return void
	 */
	public function testTheJudgeIsRecordedOnTheCase(): void {
		$service = $this->judgementOver(caseType: $this->caseType());

		$service->judge(
			caseId: 'case-1',
			verdict: AdmissibilityJudgement::INADMISSIBLE,
			reason: 'De aanvraag is te laat ingediend.',
			judgedBy: 'intake-1',
		);

		$record = $this->saved[AdmissibilityJudgement::RECORD];
		self::assertSame(expected: 'intake-1', actual: $record['judgedBy']);
		self::assertSame(
			expected: AdmissibilityJudgement::INADMISSIBLE,
			actual: $record['verdict'],
		);
		self::assertNotSame(expected: '', actual: $record['judgedOn']);
	}//end testTheJudgeIsRecordedOnTheCase()

	/**
	 * The applicant is told through the declared moment, with the reason.
	 *
	 * @return void
	 */
	public function testTheApplicantIsToldThroughTheDeclaredMoment(): void {
		$service = $this->judgementOver(caseType: $this->caseType());

		$answer = $service->judge(
			caseId: 'case-1',
			verdict: AdmissibilityJudgement::INADMISSIBLE,
			reason: 'De aanvraag is te laat ingediend.',
			judgedBy: 'intake-1',
		);

		self::assertTrue(condition: $answer['applicantTold']);
		self::assertSame(expected: AdmissibilityJudgement::MOMENT, actual: $this->letter['moment']);
		self::assertSame(expected: AdmissibilityJudgement::TEMPLATE, actual: $this->letter['template']);
		// The reason travels into the letter. A letter that says only "your
		// application was not considered" sends the applicant to the counter.
		self::assertSame(
			expected: 'De aanvraag is te laat ingediend.',
			actual: $this->letter['context']['reason'],
		);
	}//end testTheApplicantIsToldThroughTheDeclaredMoment()

	/**
	 * A letter that could not go out does not leave the case open.
	 *
	 * @return void
	 */
	public function testALetterThatCouldNotBeSentStillClosesTheCase(): void {
		$service = $this->judgementOver(caseType: $this->caseType(), messageSent: false);

		$answer = $service->judge(
			caseId: 'case-1',
			verdict: AdmissibilityJudgement::INADMISSIBLE,
			reason: 'De aanvraag is te laat ingediend.',
			judgedBy: 'intake-1',
		);

		self::assertTrue(condition: $answer['closed']);
		self::assertFalse(condition: $answer['applicantTold']);
		// And it says WHY, so a surface can show it rather than leaving the
		// silence to be discovered by the applicant.
		self::assertSame(expected: 'no-address', actual: $answer['applicantMessage']['reason']);
	}//end testALetterThatCouldNotBeSentStillClosesTheCase()

	/**
	 * An admissible verdict is recorded, and moves nothing.
	 *
	 * Moving the case on here would be a second mover of the same case beside
	 * the transition the phase already offers, and the two would disagree the
	 * first time somebody added a guard to that transition.
	 *
	 * @return void
	 */
	public function testAnAdmissibleVerdictRecordsAndMovesNothing(): void {
		$service = $this->judgementOver(caseType: $this->caseType());

		$answer = $service->judge(
			caseId: 'case-1',
			verdict: AdmissibilityJudgement::ADMISSIBLE,
			reason: 'De aanvraag is compleet.',
			judgedBy: 'intake-1',
		);

		self::assertFalse(condition: $answer['closed']);
		self::assertSame(expected: [], actual: $this->closed);
		self::assertSame(
			expected: AdmissibilityJudgement::ADMISSIBLE,
			actual: $this->saved[AdmissibilityJudgement::RECORD]['verdict'],
		);
	}//end testAnAdmissibleVerdictRecordsAndMovesNothing()

	/**
	 * A verdict with no reason is refused rather than stored.
	 *
	 * @return void
	 */
	public function testAVerdictWithNoReasonIsRefused(): void {
		$service = $this->judgementOver(caseType: $this->caseType());

		try {
			$service->judge(
				caseId: 'case-1',
				verdict: AdmissibilityJudgement::INADMISSIBLE,
				reason: '   ',
				judgedBy: 'intake-1',
			);
			self::fail(message: 'A verdict the applicant may object to must carry its reason.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: 'admissibility-reason-required', actual: $refusal->getRule());
		}

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAVerdictWithNoReasonIsRefused()

	/**
	 * A case type that judges admissibility with no result cannot close on one.
	 *
	 * @return void
	 */
	public function testAnUndeclaredResultRefusesTheCloseAndWarnsAtPublication(): void {
		$service = $this->judgementOver(caseType: $this->caseType(result: ''));

		self::assertNotSame(
			expected: [],
			actual: $service->publicationWarnings(caseType: $this->caseType(result: '')),
		);

		try {
			$service->judge(
				caseId: 'case-1',
				verdict: AdmissibilityJudgement::INADMISSIBLE,
				reason: 'Te laat ingediend.',
				judgedBy: 'intake-1',
			);
			self::fail(message: 'A close with no result is a case nobody can read afterwards.');
		} catch (RefusedException $refusal) {
			self::assertSame(
				expected: 'admissibility-result-not-declared',
				actual: $refusal->getRule(),
			);
		}

		self::assertSame(expected: [], actual: $this->closed);
	}//end testAnUndeclaredResultRefusesTheCloseAndWarnsAtPublication()

	/**
	 * A case type that asks for no verdict refuses one.
	 *
	 * @return void
	 */
	public function testACaseTypeThatAsksForNoVerdictRefusesOne(): void {
		$service = $this->judgementOver(caseType: $this->caseType(enabled: false));

		try {
			$service->judge(
				caseId: 'case-1',
				verdict: AdmissibilityJudgement::INADMISSIBLE,
				reason: 'Te laat ingediend.',
				judgedBy: 'intake-1',
			);
			self::fail(message: 'A verdict on a case type that declares none is a close nobody asked for.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: 'admissibility-not-declared', actual: $refusal->getRule());
		}
	}//end testACaseTypeThatAsksForNoVerdictRefusesOne()
}//end class
