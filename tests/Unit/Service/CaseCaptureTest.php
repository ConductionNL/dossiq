<?php

/**
 * Tests for attaching a voice note or a screen capture to a case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Conversation\CaseCaptureService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;

/**
 * A capture lands as a case document, and only a capture does.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseCaptureTest extends TestCase {

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The service under test.
	 *
	 * @var CaseCaptureService
	 */
	private CaseCaptureService $service;

	/**
	 * Build the service over an in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'document_schema' => 'document',
					'case_document_schema' => 'caseDocument',
					default => '',
				};
			}
		);

		$this->service = new CaseCaptureService(settingsService: $settings);
	}//end setUp()

	/**
	 * A constatering ter plaatse is a photo and a voice note, and both land on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAVoiceNoteBecomesACaseDocument(): void {
		$result = $this->service->attachCapture(
			caseId: 'case-1',
			fileName: 'constatering.ogg',
			mimeType: 'audio/ogg',
			content: base64_encode('bytes'),
			taskId: null,
			author: 'inspecteur',
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('case-1', $result['document']['case']);

		$documents = $this->register->all(schema: 'document');
		$this->assertCount(1, $documents);
		$this->assertSame('constatering.ogg', reset($documents)['fileName']);
		$this->assertSame('Spraaknotitie', reset($documents)['description']);
	}//end testAVoiceNoteBecomesACaseDocument()

	/**
	 * A capture made for a task says which task, and still files on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testACaptureOnATaskFollowsTheTaskOntoTheCase(): void {
		$result = $this->service->attachCapture(
			caseId: 'case-1',
			fileName: 'scherm.webm',
			mimeType: 'video/webm',
			content: base64_encode('bytes'),
			taskId: 'task-3',
			author: 'anna',
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('case-1', $result['document']['case']);
		$this->assertStringContainsString('task-3', $result['document']['description']);
	}//end testACaptureOnATaskFollowsTheTaskOntoTheCase()

	/**
	 * What a recorder did not produce is not a capture, and is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAPdfIsNotACapture(): void {
		$result = $this->service->attachCapture(
			caseId: 'case-1',
			fileName: 'brief.pdf',
			mimeType: 'application/pdf',
			content: base64_encode('bytes'),
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(CaseCaptureService::REASON_NOT_A_CAPTURE, $result['reason']);
		$this->assertSame([], $this->register->all(schema: 'document'));
	}//end testAPdfIsNotACapture()

	/**
	 * A capture with no bytes is refused rather than filed empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAnEmptyCaptureIsRefused(): void {
		$result = $this->service->attachCapture(
			caseId: 'case-1',
			fileName: 'leeg.ogg',
			mimeType: 'audio/ogg',
			content: '',
		);

		$this->assertFalse($result['ok']);
		$this->assertSame(CaseCaptureService::REASON_EMPTY, $result['reason']);
	}//end testAnEmptyCaptureIsRefused()

	/**
	 * dossiq recognises what a platform recorder produces, and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testOnlyRecorderOutputCountsAsACapture(): void {
		$this->assertTrue($this->service->isCapture(mimeType: 'audio/ogg'));
		$this->assertTrue($this->service->isCapture(mimeType: 'video/webm'));
		$this->assertTrue($this->service->isCapture(mimeType: 'image/jpeg'));
		$this->assertFalse($this->service->isCapture(mimeType: 'application/pdf'));
		$this->assertFalse($this->service->isCapture(mimeType: 'text/plain'));
	}//end testOnlyRecorderOutputCountsAsACapture()

}//end class
