<?php

/**
 * PortalAnswerRead Unit Tests
 *
 * A forwarded answer read into a submission for the verified resident: the
 * request id and the answer (a non-string as empty), and the files under
 * `bijlagen` read into bytes with their base name, unreadable ones skipped;
 * more than five files, or one over 10 MB, read as no submission at all.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\PortalAnswerRead;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PortalAnswerRead.
 *
 * @covers \OCA\Dossiq\Controller\Support\PortalAnswerRead
 * @uses   \OCA\Dossiq\Portal\WooAnswerSubmission
 */
final class PortalAnswerReadTest extends TestCase {

	/**
	 * Temporary files to remove after each test.
	 *
	 * @var list<string>
	 */
	private array $tempFiles = [];

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (is_file(filename: $file) === true) {
				unlink(filename: $file);
			}
		}
	}//end tearDown()

	/**
	 * A temporary file holding the content.
	 *
	 * @param string $content The content.
	 *
	 * @return string Its path.
	 */
	private function tempFile(string $content): string {
		$path = (string)tempnam(directory: sys_get_temp_dir(), prefix: 'answer');
		file_put_contents(filename: $path, data: $content);
		$this->tempFiles[] = $path;
		return $path;
	}//end tempFile()

	/**
	 * A request carrying the given parameters and uploads.
	 *
	 * @param array<int, array<int, mixed>> $params   The getParam map.
	 * @param mixed                         $uploaded What getUploadedFile answers for `bijlagen`.
	 *
	 * @return IRequest
	 */
	private function request(array $params, mixed $uploaded): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap($params);
		$request->method('getUploadedFile')->with('bijlagen')->willReturn($uploaded);
		return $request;
	}//end request()

	/**
	 * Without files the submission carries the resident, the request id and the answer.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutFilesIsReadForTheResident(): void {
		$read = new PortalAnswerRead(request: $this->request(params: [['requestId', '', 'req-1'], ['antwoord', '', 'Mijn antwoord']], uploaded: null));

		$submission = $read->read(subjectRef: 'bsn-1');

		$this->assertNotNull(actual: $submission);
		$this->assertSame(
			expected: ['bsn-1', 'req-1', 'Mijn antwoord', []],
			actual: [$submission->subjectRef, $submission->requestId, $submission->answer, $submission->attachments]
		);
	}//end testAnAnswerWithoutFilesIsReadForTheResident()

	/**
	 * A parameter that is not a string is read as empty.
	 *
	 * @return void
	 */
	public function testANonStringParameterIsReadAsEmpty(): void {
		$read = new PortalAnswerRead(request: $this->request(params: [['requestId', '', ['x']], ['antwoord', '', 'Ja']], uploaded: null));

		$this->assertSame(expected: '', actual: $read->read(subjectRef: 'bsn-1')?->requestId);
	}//end testANonStringParameterIsReadAsEmpty()

	/**
	 * Several files are read with their base names, and an unreadable one is skipped.
	 *
	 * @return void
	 */
	public function testFilesAreReadWithTheirBaseNamesAndUnreadableOnesSkipped(): void {
		$path = $this->tempFile(content: 'inhoud');
		$read = new PortalAnswerRead(
			request: $this->request(
				params: [['requestId', '', 'req-1'], ['antwoord', '', 'Ja']],
				uploaded: ['name' => ['../brief.pdf', 'leeg.pdf'], 'tmp_name' => [$path, '/nonexistent/answer'], 'size' => [6, 1]]
			)
		);

		$this->assertSame(expected: [['name' => 'brief.pdf', 'content' => 'inhoud']], actual: $read->read(subjectRef: 'bsn-1')?->attachments);
	}//end testFilesAreReadWithTheirBaseNamesAndUnreadableOnesSkipped()

	/**
	 * Six files, or one over 10 MB, is no submission; exactly 10 MB is one.
	 *
	 * @return void
	 */
	public function testTooManyOrTooLargeFilesAreNoSubmission(): void {
		$params = [['requestId', '', 'req-1'], ['antwoord', '', 'Ja']];
		$six = ['name' => array_fill(0, 6, 'a.pdf'), 'tmp_name' => array_fill(0, 6, '/nonexistent'), 'size' => array_fill(0, 6, 1)];

		$this->assertNull(actual: (new PortalAnswerRead(request: $this->request(params: $params, uploaded: $six)))->read(subjectRef: 'bsn-1'));
		$this->assertNull(
			actual: (new PortalAnswerRead(
				request: $this->request(params: $params, uploaded: ['name' => 'big.pdf', 'tmp_name' => '/nonexistent', 'size' => 10485761])
			))->read(subjectRef: 'bsn-1')
		);
		$this->assertNotNull(
			actual: (new PortalAnswerRead(
				request: $this->request(params: $params, uploaded: ['name' => 'max.pdf', 'tmp_name' => '/nonexistent', 'size' => 10485760])
			))->read(subjectRef: 'bsn-1')
		);
	}//end testTooManyOrTooLargeFilesAreNoSubmission()
}//end class
