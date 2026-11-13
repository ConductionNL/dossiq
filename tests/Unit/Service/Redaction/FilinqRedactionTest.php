<?php

/**
 * Tests for the filinq calls of the document redaction step.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\Redaction\FilinqRedaction;
use OCA\Dossiq\Service\Redaction\RedactionRun;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Filinq's answers pass through, and every way filinq fails becomes a named refusal.
 *
 * @covers \OCA\Dossiq\Service\Redaction\FilinqRedaction
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Redaction\RedactionRun
 *
 * @spec openspec/specs/document-redaction/spec.md
 */
class FilinqRedactionTest extends TestCase {

	/**
	 * The filinq client.
	 *
	 * @var FilinqRedactionClient|MockObject
	 */
	private FilinqRedactionClient $client;

	/**
	 * The helper under test.
	 *
	 * @var FilinqRedaction
	 */
	private FilinqRedaction $filinq;

	/**
	 * Build the helper with an identity translator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(FilinqRedactionClient::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->filinq = new FilinqRedaction(filinq: $this->client, l10n: $l10n);
	}//end setUp()

	/**
	 * A step on file 42, by anna, with two passages.
	 *
	 * @return RedactionRun
	 */
	private function step(): RedactionRun {
		$run = new RedactionRun(caseId: 'case-1', documentRef: 'doc-1', userId: 'anna');
		$run->fileId = 42;
		$run->redaction = ['entities' => [['text' => 'Jan'], ['text' => '06-12345678']]];

		return $run;
	}//end step()

	/**
	 * Assert a refusal carries this rule, sentence and status.
	 *
	 * @param RefusedException $e        The refusal.
	 * @param string           $rule     The expected rule.
	 * @param string           $sentence The expected sentence.
	 * @param int              $status   The expected status.
	 *
	 * @return void
	 */
	private function assertRefusal(RefusedException $e, string $rule, string $sentence, int $status): void {
		$this->assertSame($rule, $e->getRule());
		$this->assertSame($sentence, $e->getSentence());
		$this->assertSame($status, $e->getStatus());
	}//end assertRefusal()

	/**
	 * Detection answers filinq's passages for the file.
	 *
	 * @return void
	 */
	public function testDetectAnswersFilinqsPassages(): void {
		$this->client->expects($this->once())->method('detect')->with(42)->willReturn([['text' => 'Jan']]);

		$this->assertSame([['text' => 'Jan']], $this->filinq->detect(fileId: 42));
	}//end testDetectAnswersFilinqsPassages()

	/**
	 * Detection failing is an indeterminate refusal, the failure kept as its cause.
	 *
	 * @return void
	 */
	public function testDetectFailureIsRefused(): void {
		$cause = new RuntimeException('down');
		$this->client->method('detect')->willThrowException($cause);

		try {
			$this->filinq->detect(fileId: 42);
			$this->fail('A failed detection answered passages.');
		} catch (RefusedException $e) {
			$this->assertRefusal(
				e: $e,
				rule: 'detection-unavailable',
				sentence: 'Filinq could not detect the passages of this document. Upload a redacted version instead.',
				status: RefusedException::STATUS_INDETERMINATE
			);
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testDetectFailureIsRefused()

	/**
	 * The check is recorded on the run's file, by the run's user.
	 *
	 * @return void
	 */
	public function testMarkCheckedRecordsTheCheck(): void {
		$checked = [['text' => 'Jan']];
		$this->client->expects($this->once())->method('markChecked')->with(42, $checked, 'anna')->willReturn([]);

		$this->filinq->markChecked(run: $this->step(), checked: $checked);
	}//end testMarkCheckedRecordsTheCheck()

	/**
	 * A check filinq cannot record is an indeterminate refusal.
	 *
	 * @return void
	 */
	public function testMarkCheckedFailureIsRefused(): void {
		$this->client->method('markChecked')->willThrowException(new RuntimeException('down'));

		try {
			$this->filinq->markChecked(run: $this->step(), checked: []);
			$this->fail('A check filinq did not record was accepted.');
		} catch (RefusedException $e) {
			$this->assertRefusal(
				e: $e,
				rule: 'check-not-recorded',
				sentence: 'Filinq could not record your check. Try again, or upload a redacted version.',
				status: RefusedException::STATUS_INDETERMINATE
			);
		}
	}//end testMarkCheckedFailureIsRefused()

	/**
	 * A redacted outcome passes through, asked for with the run's passages and user.
	 *
	 * @return void
	 */
	public function testAnonymizeAnswersTheRedactedOutcome(): void {
		$outcome = ['status' => 'redacted', 'fileId' => 99];
		$this->client->expects($this->once())
			->method('anonymize')
			->with(42, [['text' => 'Jan'], ['text' => '06-12345678']], 'anna')
			->willReturn($outcome);

		$this->assertSame($outcome, $this->filinq->anonymize(run: $this->step()));
	}//end testAnonymizeAnswersTheRedactedOutcome()

	/**
	 * A run without passages asks filinq with an empty list.
	 *
	 * @return void
	 */
	public function testAnonymizeWithoutPassagesSendsAnEmptyList(): void {
		$run = $this->step();
		$run->redaction = [];
		$this->client->expects($this->once())
			->method('anonymize')
			->with(42, [], 'anna')
			->willReturn(['status' => 'redacted']);

		$this->assertSame(['status' => 'redacted'], $this->filinq->anonymize(run: $run));
	}//end testAnonymizeWithoutPassagesSendsAnEmptyList()

	/**
	 * Filinq failing to write the copy is an indeterminate refusal.
	 *
	 * @return void
	 */
	public function testAnonymizeFailureIsRefused(): void {
		$this->client->method('anonymize')->willThrowException(new RuntimeException('down'));

		try {
			$this->filinq->anonymize(run: $this->step());
			$this->fail('A failed redaction answered an outcome.');
		} catch (RefusedException $e) {
			$this->assertRefusal(
				e: $e,
				rule: 'redaction-failed',
				sentence: 'Filinq could not make the redacted copy. Try again, or upload a redacted version.',
				status: RefusedException::STATUS_INDETERMINATE
			);
		}
	}//end testAnonymizeFailureIsRefused()

	/**
	 * Each outcome without a copy has its own refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: int}>
	 */
	public static function outcomesWithoutACopy(): array {
		return [
			'detection off' => [
				['status' => 'detection_unavailable'],
				'detection-unavailable',
				'Entity detection is off, so filinq makes no redacted copy. Ask an administrator to turn it on, or upload a redacted version.',
				RefusedException::STATUS_INDETERMINATE,
			],
			'not checked' => [
				['status' => 'not_checked'],
				'not-checked',
				'Filinq has no check for these passages. Confirm the list again.',
				RefusedException::STATUS_REFUSED,
			],
			'other status' => [
				['status' => 'skipped'],
				'no-redacted-copy',
				'Filinq wrote no redacted copy of this document. Upload a redacted version instead.',
				RefusedException::STATUS_REFUSED,
			],
			'no status' => [
				[],
				'no-redacted-copy',
				'Filinq wrote no redacted copy of this document. Upload a redacted version instead.',
				RefusedException::STATUS_REFUSED,
			],
		];
	}//end outcomesWithoutACopy()

	/**
	 * An outcome in which filinq wrote no copy is refused, by what filinq answered.
	 *
	 * @param array<string, mixed> $outcome  Filinq's answer.
	 * @param string               $rule     The expected rule.
	 * @param string               $sentence The expected sentence.
	 * @param int                  $status   The expected status.
	 *
	 * @return void
	 *
	 * @dataProvider outcomesWithoutACopy
	 */
	public function testAnOutcomeWithoutACopyIsRefused(array $outcome, string $rule, string $sentence, int $status): void {
		$this->client->method('anonymize')->willReturn($outcome);

		try {
			$this->filinq->anonymize(run: $this->step());
			$this->fail('An outcome without a copy was accepted.');
		} catch (RefusedException $e) {
			$this->assertRefusal(e: $e, rule: $rule, sentence: $sentence, status: $status);
			$this->assertNull($e->getPrevious());
		}
	}//end testAnOutcomeWithoutACopyIsRefused()

}//end class
