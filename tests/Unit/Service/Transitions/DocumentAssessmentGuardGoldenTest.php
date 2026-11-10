<?php

/**
 * Characterisation tests for DocumentAssessmentGuard::evaluate: every
 * requirement's verdict, sentence and details, which reads it makes, and what
 * it logs when the documents cannot be read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Assessment\DocumentSearchOutcome;
use OCA\Dossiq\Service\Transitions\DocumentAssessmentGuard;
use OCA\Dossiq\Service\Transitions\GuardResult;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class DocumentAssessmentGuardGoldenTest extends TestCase {

	/**
	 * Every read and log line, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * A guard over scripted assessments.
	 *
	 * @param mixed $underReview  What getDocumentsUnderReview answers; a Throwable is thrown.
	 * @param mixed $outstanding  What getOutstanding answers; a Throwable is thrown.
	 * @param bool  $nothingFound Whether the case records that its search found nothing.
	 * @param mixed $copies       What partlyPublicWithoutCopy answers; a Throwable is thrown.
	 *
	 * @return DocumentAssessmentGuard The guard.
	 */
	private function guard(mixed $underReview = [], mixed $outstanding = ['count' => 0], bool $nothingFound = false, mixed $copies = ['waiting' => [], 'partlyPublic' => 0]): DocumentAssessmentGuard {
		$answer = function (string $read, mixed $value): \Closure {
			return function (string $caseId) use ($read, $value): array {
				$this->calls[] = [$read, $caseId];
				if ($value instanceof \Throwable) {
					throw $value;
				}

				return $value;
			};
		};
		$assessments = $this->createMock(WOODocumentAssessmentService::class);
		$assessments->method('getDocumentsUnderReview')->willReturnCallback($answer('underReview', $underReview));
		$assessments->method('getOutstanding')->willReturnCallback($answer('outstanding', $outstanding));
		$assessments->method('partlyPublicWithoutCopy')->willReturnCallback($answer('copies', $copies));

		$search = $this->createMock(DocumentSearchOutcome::class);
		$search->method('recordsNothingFound')->willReturn($nothingFound);

		$calls = &$this->calls;
		$logger = new class($calls) extends AbstractLogger {
			public function __construct(private array &$calls) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->calls[] = ['log', (string)$level, (string)$message, $context];
			}
		};

		return new DocumentAssessmentGuard(assessments: $assessments, logger: $logger, l10n: new SourceL10n(), searchOutcome: $search);
	}//end guard()

	/**
	 * A verdict as one comparable row.
	 *
	 * @param GuardResult $result The verdict.
	 *
	 * @return array The row.
	 */
	private function row(GuardResult $result): array {
		return [$result->passed, $result->failureMessage, $result->details];
	}//end row()

	public function testAnUnknownRequirementRefuses(): void {
		self::assertSame(
			[false, 'Document assessment guard with an unknown requirement: published', ['require' => 'published']],
			$this->row($this->guard()->evaluate(['require' => 'published'], ['id' => 'c'], 'u'))
		);
		self::assertSame([], $this->calls);
	}//end testAnUnknownRequirementRefuses()

	public function testNothingFoundPassesEveryRequirement(): void {
		foreach (['gathered', 'assessed', 'redacted'] as $require) {
			self::assertSame(
				[true, null, ['require' => $require, 'nothingFound' => true]],
				$this->row($this->guard(nothingFound: true)->evaluate(['require' => $require], [], 'u'))
			);
		}

		self::assertSame([], $this->calls);
	}//end testNothingFoundPassesEveryRequirement()

	public function testACaseWithoutIdIsNotRecognised(): void {
		self::assertSame(
			[false, 'Case not recognised for the document check', []],
			$this->row($this->guard()->evaluate([], ['title' => 'x'], 'u'))
		);
	}//end testACaseWithoutIdIsNotRecognised()

	public function testTheCaseIdIsReadFromIdThenUuidThenSelf(): void {
		$this->guard()->evaluate([], ['id' => 'by-id', 'uuid' => 'by-uuid'], 'u');
		$this->guard()->evaluate([], ['uuid' => 'by-uuid', '@self' => ['id' => 'by-self']], 'u');
		$this->guard()->evaluate([], ['@self' => ['id' => 'by-self']], 'u');

		self::assertSame(['by-id', 'by-uuid', 'by-self'], array_column(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'underReview'), 1));
	}//end testTheCaseIdIsReadFromIdThenUuidThenSelf()

	public function testAssessedNeedsDocumentsAndNoneOutstanding(): void {
		self::assertSame(
			[false, 'No documents have been collected yet. Found nothing? Record that with an explanation', ['require' => 'assessed', 'documents' => 0]],
			$this->row($this->guard(underReview: [])->evaluate([], ['id' => 'c'], 'u'))
		);
		self::assertSame([['underReview', 'c'], ['outstanding', 'c']], $this->calls);

		self::assertSame(
			[false, '2 of 3 documents are still waiting for an assessment', ['require' => 'assessed', 'documents' => 3, 'outstanding' => 2]],
			$this->row($this->guard(underReview: [1, 2, 3], outstanding: ['count' => '2'])->evaluate([], ['id' => 'c'], 'u'))
		);
		self::assertSame(
			[true, null, ['require' => 'assessed', 'documents' => 3]],
			$this->row($this->guard(underReview: [1, 2, 3], outstanding: [])->evaluate(['require' => 'assessed'], ['id' => 'c'], 'u'))
		);
	}//end testAssessedNeedsDocumentsAndNoneOutstanding()

	public function testGatheredNeedsDocumentsOnlyAndDoesNotAskForOutstanding(): void {
		self::assertSame(
			[true, null, ['require' => 'gathered', 'documents' => 1]],
			$this->row($this->guard(underReview: [1], outstanding: ['count' => 5])->evaluate(['require' => 'gathered'], ['id' => 'c'], 'u'))
		);
		self::assertSame([['underReview', 'c']], $this->calls);
	}//end testGatheredNeedsDocumentsOnlyAndDoesNotAskForOutstanding()

	public function testUnreadableDocumentsAreLoggedAndRefuse(): void {
		self::assertSame(
			[false, 'The documents of this case could not be read', ['require' => 'assessed', 'unreadable' => true]],
			$this->row($this->guard(underReview: [1], outstanding: new \RuntimeException('down'))->evaluate([], ['id' => 'c'], 'u'))
		);
		self::assertSame(
			['log', 'warning', 'Dossiq: the document assessment guard could not read the documents of a case', ['caseId' => 'c', 'exception' => 'down']],
			end($this->calls)
		);
	}//end testUnreadableDocumentsAreLoggedAndRefuse()

	public function testRedactedAsksForCopiesOnly(): void {
		self::assertSame(
			[false, '1 of 2 partly public documents are still waiting for a redacted version', ['require' => 'redacted', 'partlyPublic' => 2, 'waiting' => 1]],
			$this->row($this->guard(copies: ['waiting' => ['d'], 'partlyPublic' => 2])->evaluate(['require' => 'redacted'], ['id' => 'c'], 'u'))
		);
		self::assertSame([['copies', 'c']], $this->calls);

		self::assertSame(
			[true, null, ['require' => 'redacted', 'partlyPublic' => 2]],
			$this->row($this->guard(copies: ['waiting' => [], 'partlyPublic' => 2])->evaluate(['require' => 'redacted'], ['id' => 'c'], 'u'))
		);

		$this->calls = [];
		self::assertSame(
			[false, 'The documents of this case could not be read', ['require' => 'redacted', 'unreadable' => true]],
			$this->row($this->guard(copies: new \RuntimeException('gone'))->evaluate(['require' => 'redacted'], ['id' => 'c'], 'u'))
		);
		self::assertSame(
			['log', 'warning', 'Dossiq: the document assessment guard could not read the redactions of a case', ['caseId' => 'c', 'exception' => 'gone']],
			end($this->calls)
		);
	}//end testRedactedAsksForCopiesOnly()
}//end class
