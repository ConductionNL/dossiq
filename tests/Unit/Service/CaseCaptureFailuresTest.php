<?php

/**
 * Case capture failures test
 *
 * A capture that cannot be stored must not look stored: without
 * OpenRegister, when the document is not saved, and when the document is
 * saved but not filed on the case, the service throws with the step that
 * failed instead of answering ok.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Conversation\CaptureFiling;
use OCA\Dossiq\Service\Conversation\CaseCaptureService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Conversation\CaseCaptureService
 * @uses   \OCA\Dossiq\Service\Conversation\CaptureFiling
 */
class CaseCaptureFailuresTest extends TestCase {

	/**
	 * The service over an object service (or none) whose saves answer the given values in turn.
	 *
	 * @param array<int, mixed>|null $answers What each saveObject answers; null for no object service.
	 * @param array<int, array>      $saved   Receives the objects saved.
	 *
	 * @return CaseCaptureService
	 */
	private function service(?array $answers, array &$saved = []): CaseCaptureService {
		$objects = null;
		if ($answers !== null) {
			$objects = new class($answers, $saved) {
				/**
				 * @param array<int, mixed> $answers The answers.
				 * @param array<int, array> $saved   The objects saved.
				 */
				public function __construct(
					private array $answers,
					private array &$saved,
				) {
				}

				/**
				 * @param array<string, mixed> $object   The object.
				 * @param mixed                $register The register.
				 * @param mixed                $schema   The schema.
				 * @param string|null          $uuid     The uuid.
				 *
				 * @return mixed
				 */
				public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): mixed {
					$this->saved[] = ['schema' => $schema, 'object' => $object];
					return array_shift($this->answers);
				}
			};
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'document_schema' => 'document', 'case_document_schema' => 'caseDocument'][$key] ?? ''
		);

		return new CaseCaptureService($settings);
	}//end service()

	/**
	 * Without OpenRegister nothing can be stored.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheCaptureIsNotStored(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->service(null)->attachCapture('case-1', 'memo.webm', 'audio/webm', 'bytes');
	}//end testWithoutOpenRegisterTheCaptureIsNotStored()

	/**
	 * A document the store does not return is not filed on the case.
	 *
	 * @return void
	 */
	public function testADocumentNotStoredIsNotFiled(): void {
		$saved = [];
		$service = $this->service([null], $saved);

		try {
			$service->attachCapture('case-1', 'memo.webm', 'audio/webm', 'bytes');
			$this->fail('a capture that was not stored answered ok');
		} catch (RuntimeException $e) {
			$this->assertSame('The capture could not be stored', $e->getMessage());
		}

		$this->assertSame(['document'], array_column($saved, 'schema'), 'no case document is written');
	}//end testADocumentNotStoredIsNotFiled()

	/**
	 * A stored document that cannot be filed on the case is reported, not answered ok.
	 *
	 * @return void
	 */
	public function testADocumentThatCannotBeFiledIsReported(): void {
		$saved = [];
		$service = $this->service([['uuid' => 'doc-1'], 'not a row'], $saved);

		try {
			$service->attachCapture('case-1', 'foto.jpg', 'image/jpeg', 'bytes', 'task-7', 'anna');
			$this->fail('a capture that was not filed answered ok');
		} catch (RuntimeException $e) {
			$this->assertSame('The capture could not be filed on the case', $e->getMessage());
		}

		$this->assertSame(['document', 'caseDocument'], array_column($saved, 'schema'));
		$this->assertSame('doc-1', $saved[1]['object']['document'], 'the case document names the document by its uuid');
		$this->assertSame('Foto bij taak task-7', $saved[1]['object']['description']);
		$this->assertSame('anna', $saved[0]['object']['author']);
	}//end testADocumentThatCannotBeFiledIsReported()
}//end class
