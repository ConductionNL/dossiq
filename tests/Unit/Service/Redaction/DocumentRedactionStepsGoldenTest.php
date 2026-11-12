<?php

/**
 * The steps of a redaction, each refusal and each write, over doubles.
 *
 * Pins every refusal of detect, check, redact and upload with its rule,
 * sentence and status, the order the refusals are reached in, and what each
 * successful step reads, writes and answers, call by call.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\Redaction\DocumentRedactionService;
use OCA\Dossiq\Service\Redaction\RedactedCopyRecorder;
use OCA\Dossiq\Service\Redaction\RedactionEntities;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Redaction\DocumentRedactionService
 */
class DocumentRedactionStepsGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * What the doubles answer, by method.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * A double of a class whose named methods record their call and answer from the world.
	 *
	 * @param string             $class   The class.
	 * @param array<int, string> $methods The methods.
	 *
	 * @return object
	 */
	private function double(string $class, array $methods): object {
		$double = $this->createMock($class);
		foreach ($methods as $method) {
			$double->method($method)->willReturnCallback(
				function (...$args) use ($method) {
					$this->calls[] = [$method, ...array_values($args)];
					$answer = $this->world[$method] ?? null;
					if ($answer instanceof \Throwable) {
						throw $answer;
					}

					return is_callable($answer) === true ? $answer(...$args) : $answer;
				}
			);
		}

		return $double;
	}//end double()

	/**
	 * The service over recording doubles.
	 *
	 * @return DocumentRedactionService
	 */
	private function service(): DocumentRedactionService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DocumentRedactionService(
			$this->double(WOODocumentAssessmentService::class, ['findAssessment', 'saveRedaction']),
			$this->double(FilinqRedactionClient::class, ['detect', 'markChecked', 'anonymize']),
			$this->double(WOOAnonymisationAssistService::class, ['spansFor']),
			$this->double(DocumentText::class, ['extracted']),
			$this->double(WooCaseDocuments::class, ['meta']),
			$this->double(ZgwDocumentService::class, ['getFileId']),
			$this->double(RedactedCopyRecorder::class, ['recordFile', 'storeUpload']),
			$this->double(RedactionEntities::class, ['fromFilinq', 'fromSpans', 'fromOfficer', 'merge']),
			$l10n
		);
	}//end service()

	/**
	 * A world where every step succeeds.
	 *
	 * @param array<string, mixed> $redaction The stored redaction record.
	 *
	 * @return void
	 */
	private function happy(array $redaction = []): void {
		$this->world = [
			'findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => $redaction, 'redactedDocumentRef' => 'copy-0'],
			'meta' => ['title' => 'Nota', 'fileId' => 7],
			'detect' => [['raw' => 1]],
			'fromFilinq' => ['F'],
			'extracted' => 'tekst',
			'spansFor' => ['spans' => [['s' => 1]], 'source' => 'hermiq'],
			'fromSpans' => ['S'],
			'merge' => ['F', 'S'],
			'fromOfficer' => static fn (array $entities): array => $entities,
			'markChecked' => [],
			'anonymize' => ['status' => 'redacted', 'anonymizedFileId' => '9', 'detectionBackend' => 'presidio'],
			'recordFile' => 'copy-1',
			'storeUpload' => ['fileId' => 11, 'recordId' => 'copy-2'],
			'saveRedaction' => [],
		];
	}//end happy()

	/**
	 * The refusal a call raises.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: string, 1: string, 2: int, 3: bool} Rule, sentence, status, and whether it carries the cause.
	 */
	private function refusal(callable $call): array {
		try {
			$call();
		} catch (RefusedException $e) {
			return [$e->getRule(), $e->getSentence(), $e->getStatus(), $e->getPrevious() !== null];
		}

		$this->fail('The call must be refused.');
	}//end refusal()

	/**
	 * The method names called, in order.
	 *
	 * @return array<int, string>
	 */
	private function called(): array {
		return array_column($this->calls, 0);
	}//end called()

	/**
	 * Detect reads, stores and answers in this order.
	 *
	 * @return void
	 */
	public function testDetect(): void {
		$this->happy();
		$answer = $this->service()->detect('case-1', 'doc-1', 'ann');

		$this->assertSame(['findAssessment', 'meta', 'detect', 'fromFilinq', 'extracted', 'spansFor', 'fromSpans', 'merge', 'saveRedaction'], $this->called());
		$this->assertSame(['extracted', ['fileId' => 7]], $this->calls[4]);
		$this->assertSame(['spansFor', 'tekst', 'doc-1'], $this->calls[5]);
		$redaction = $answer['redaction'];
		$this->assertMatchesRegularExpression('/^\d{4}-/', $redaction['detectedAt']);
		unset($redaction['detectedAt']);
		$this->assertSame(['entities' => ['F', 'S'], 'sourceFileId' => 7, 'detectedBy' => 'ann', 'spanSource' => 'hermiq'], $redaction);
		$this->assertSame(['documentRef' => 'doc-1', 'title' => 'Nota', 'text' => 'tekst'], array_slice($answer, 0, 3));
		$this->assertSame('copy-0', $answer['redactedDocumentRef']);
		$this->assertSame(['saveRedaction', 'case-1', 'doc-1', $answer['redaction'], null], $this->calls[8]);
	}//end testDetect()

	/**
	 * Check records in filinq and on the assessment, over the stored record.
	 *
	 * @return void
	 */
	public function testCheck(): void {
		$this->happy(redaction: ['detectedBy' => 'ann']);
		$redaction = $this->service()->check('case-1', 'doc-1', [['text' => 'x']], 'bob');

		$this->assertSame(['findAssessment', 'meta', 'fromOfficer', 'markChecked', 'saveRedaction'], $this->called());
		$this->assertSame(['markChecked', 7, [['text' => 'x']], 'bob'], $this->calls[3]);
		unset($redaction['checkedAt']);
		$this->assertSame(['detectedBy' => 'ann', 'entities' => [['text' => 'x']], 'sourceFileId' => 7, 'checkedBy' => 'bob'], $redaction);
		$this->assertSame(['case-1', 'doc-1', null], [$this->calls[4][1], $this->calls[4][2], $this->calls[4][4]]);
	}//end testCheck()

	/**
	 * Redact has filinq write the copy, records it and stores it on the assessment.
	 *
	 * @return void
	 */
	public function testRedact(): void {
		$this->happy(redaction: ['entities' => ['E'], 'checkedBy' => 'bob', 'sourceFileId' => 7]);
		$answer = $this->service()->redact('case-1', 'doc-1', 'cas');

		$this->assertSame(['findAssessment', 'meta', 'anonymize', 'recordFile', 'saveRedaction'], $this->called());
		$this->assertSame(['anonymize', 7, ['E'], 'cas'], $this->calls[2]);
		$this->assertSame(['recordFile', 'case-1', ['title' => 'Nota', 'fileId' => 7], 9], $this->calls[3]);
		$redaction = $answer['redaction'];
		unset($redaction['redactedAt']);
		$this->assertSame(
			['entities' => ['E'], 'checkedBy' => 'bob', 'sourceFileId' => 7, 'method' => 'filinq', 'redactedFileId' => 9, 'redactedBy' => 'cas', 'detectionBackend' => 'presidio'],
			$redaction
		);
		$this->assertSame('copy-1', $answer['redactedDocumentRef']);
		$this->assertSame(['saveRedaction', 'case-1', 'doc-1', $answer['redaction'], 'copy-1'], $this->calls[4]);
	}//end testRedact()

	/**
	 * Upload stores the version and records it on the assessment.
	 *
	 * @return void
	 */
	public function testUpload(): void {
		$this->happy(redaction: ['checkedBy' => 'bob']);
		$answer = $this->service()->upload('case-1', 'doc-1', 'nota-zwart.pdf', 'bytes', 'cas');

		$this->assertSame(['findAssessment', 'meta', 'storeUpload', 'saveRedaction'], $this->called());
		$this->assertSame(['storeUpload', 'case-1', ['title' => 'Nota', 'fileId' => 7], 'nota-zwart.pdf', 'bytes'], $this->calls[2]);
		$redaction = $answer['redaction'];
		unset($redaction['redactedAt']);
		$this->assertSame(['checkedBy' => 'bob', 'method' => 'upload', 'redactedFileId' => 11, 'redactedBy' => 'cas'], $redaction);
		$this->assertSame(['redactedDocumentRef' => 'copy-2', 'redaction' => $answer['redaction']], $answer);
		$this->assertSame(['saveRedaction', 'case-1', 'doc-1', $answer['redaction'], 'copy-2'], $this->calls[3]);
	}//end testUpload()

	/**
	 * Every refusal, with the world that reaches it.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array{0: string, 1: string, 2: int, 3: bool}}>
	 */
	public static function refusals(): array {
		$checked = ['entities' => ['E'], 'checkedBy' => 'bob', 'sourceFileId' => 7];
		return [
			'detect: public document' => ['detect', ['findAssessment' => ['classification' => 'openbaar']], ['not-partly-public', 'Only a document assessed as disclosed in part is redacted.', 409, false]],
			'detect: no assessment' => ['detect', ['findAssessment' => null], ['not-partly-public', 'Only a document assessed as disclosed in part is redacted.', 409, false]],
			'detect: unreadable' => ['detect', ['meta' => null], ['document-unreadable', 'This document could not be read.', 503, false]],
			'detect: no file' => ['detect', ['meta' => ['title' => 'Nota']], ['file-unresolved', 'This document has no file to redact. Upload a redacted version instead.', 422, false]],
			'detect: file not found' => ['detect', ['meta' => ['fileName' => 'n.pdf'], 'getFileId' => new RuntimeException('gone')], ['file-unresolved', 'This document has no file to redact. Upload a redacted version instead.', 422, true]],
			'detect: filinq down' => ['detect', ['detect' => new RuntimeException('down')], ['detection-unavailable', 'Filinq could not detect the passages of this document. Upload a redacted version instead.', 503, true]],
			'check: nothing' => ['check', ['fromOfficer' => []], ['nothing-to-redact', 'Mark at least one passage to redact, or upload a redacted version.', 422, false]],
			'check: filinq down' => ['check', ['markChecked' => new RuntimeException('down')], ['check-not-recorded', 'Filinq could not record your check. Try again, or upload a redacted version.', 503, true]],
			'check: unreadable before nothing' => ['check', ['meta' => null, 'fromOfficer' => []], ['document-unreadable', 'This document could not be read.', 503, false]],
			'redact: not checked before unreadable' => ['redact', ['meta' => null, 'findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => ['entities' => ['E']]]], ['not-checked', 'Check the passages first: confirm the list before the redacted copy is made.', 409, false]],
			'redact: no entities' => ['redact', ['findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => ['checkedBy' => 'bob']]], ['not-checked', 'Check the passages first: confirm the list before the redacted copy is made.', 409, false]],
			'redact: another file' => ['redact', ['findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => ['sourceFileId' => 8] + $checked]], ['checked-another-file', 'The document changed after the passages were checked. Detect and check them again.', 409, false]],
			'redact: filinq down' => ['redact', ['findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => $checked], 'anonymize' => new RuntimeException('down')], ['redaction-failed', 'Filinq could not make the redacted copy. Try again, or upload a redacted version.', 503, true]],
			'redact: copy not recorded' => ['redact', ['findAssessment' => ['classification' => 'deels_openbaar', 'redaction' => $checked], 'recordFile' => new RuntimeException('down')], ['copy-not-recorded', 'Filinq made the redacted copy, but it could not be recorded on the case. Try again.', 503, true]],
			'upload: public before empty' => ['upload-empty', ['findAssessment' => ['classification' => 'openbaar']], ['not-partly-public', 'Only a document assessed as disclosed in part is redacted.', 409, false]],
			'upload: empty before unreadable' => ['upload-empty', ['meta' => null], ['empty-upload', 'Choose the redacted version to upload.', 422, false]],
			'upload: unreadable' => ['upload', ['meta' => null], ['document-unreadable', 'This document could not be read.', 503, false]],
			'upload: not stored' => ['upload', ['storeUpload' => new RuntimeException('down')], ['upload-not-stored', 'The redacted version could not be stored. Try again.', 503, true]],
		];
	}//end refusals()

	/**
	 * Each refusal carries its rule, sentence, status and cause, and nothing is saved.
	 *
	 * @param string                                       $step     Which step.
	 * @param array<string, mixed>                         $world    What differs from a happy world.
	 * @param array{0: string, 1: string, 2: int, 3: bool} $expected The refusal.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testEachRefusal(string $step, array $world, array $expected): void {
		$this->happy();
		$this->world = $world + $this->world;
		$service = $this->service();

		$refusal = $this->refusal(match ($step) {
			'detect' => fn () => $service->detect('case-1', 'doc-1', 'ann'),
			'check' => fn () => $service->check('case-1', 'doc-1', [['text' => 'x']], 'bob'),
			'redact' => fn () => $service->redact('case-1', 'doc-1', 'cas'),
			'upload' => fn () => $service->upload('case-1', 'doc-1', 'n.pdf', 'bytes', 'cas'),
			'upload-empty' => fn () => $service->upload('case-1', 'doc-1', 'n.pdf', '', 'cas'),
		});

		$this->assertSame($expected, $refusal);
		$this->assertNotContains('saveRedaction', $this->called());
	}//end testEachRefusal()

	/**
	 * A status filinq answers other than redacted is refused through refusalFor.
	 *
	 * @return void
	 */
	public function testAFilinqStatusOtherThanRedactedIsRefused(): void {
		$this->happy(redaction: ['entities' => ['E'], 'checkedBy' => 'bob', 'sourceFileId' => 7]);
		$this->world['anonymize'] = ['status' => 'review-required'];

		$refusal = $this->refusal(fn () => $this->service()->redact('case-1', 'doc-1', 'cas'));

		$this->assertNotSame('', $refusal[0]);
		$this->assertNotContains('recordFile', $this->called());
		$this->assertNotContains('saveRedaction', $this->called());
	}//end testAFilinqStatusOtherThanRedactedIsRefused()
}//end class
