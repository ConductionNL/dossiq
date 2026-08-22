<?php

/**
 * EvidenceMetadataService Unit Tests.
 *
 * @category Tests
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
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\EvidenceMetadataService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EvidenceMetadataService.
 *
 * @covers \OCA\Dossiq\Service\EvidenceMetadataService
 */
class EvidenceMetadataServiceTest extends TestCase {

	/**
	 * @var EvidenceMetadataService
	 */
	private EvidenceMetadataService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new EvidenceMetadataService();
	}//end setUp()

	/**
	 * An accurate reading classifies as good with no warning.
	 *
	 * @return void
	 */
	public function testClassifyGoodGps(): void {
		$result = $this->service->classifyGps(['lat' => 52.16, 'lon' => 5.38, 'accuracy' => 8.0]);

		$this->assertSame(EvidenceMetadataService::GPS_QUALITY_GOOD, $result['quality']);
		$this->assertNull($result['warning']);
		$this->assertSame('sensor', $result['location']['source']);
		$this->assertSame(8.0, $result['location']['accuracy']);
	}//end testClassifyGoodGps()

	/**
	 * A reading worse than 50m raises a poor-accuracy warning.
	 *
	 * @return void
	 */
	public function testClassifyPoorGps(): void {
		$result = $this->service->classifyGps(['lat' => 52.16, 'lon' => 5.38, 'accuracy' => 200.0]);

		$this->assertSame(EvidenceMetadataService::GPS_QUALITY_POOR, $result['quality']);
		$this->assertNotNull($result['warning']);
		$this->assertStringContainsString('200', $result['warning']);
	}//end testClassifyPoorGps()

	/**
	 * A missing reading falls back to the case address as sensorless.
	 *
	 * @return void
	 */
	public function testClassifySensorlessFallback(): void {
		$result = $this->service->classifyGps(null, ['lat' => 52.10, 'lon' => 5.30]);

		$this->assertSame(EvidenceMetadataService::GPS_QUALITY_SENSORLESS, $result['quality']);
		$this->assertNull($result['warning']);
		$this->assertSame(52.10, $result['location']['lat']);
		$this->assertSame('sensorless', $result['location']['source']);
	}//end testClassifySensorlessFallback()

	/**
	 * The EXIF context block links inspector, case, device and template.
	 *
	 * @return void
	 */
	public function testBuildExifContext(): void {
		$context = $this->service->buildExifContext(
			[
				'inspectorRef' => 'anja.bakker',
				'caseRef' => 'ZAAK-2026-000147',
				'deviceId' => 'tablet-anja-001',
				'checklistTemplateRef' => 'checklist-1',
			],
			'2026-05-22T09:15:32+00:00'
		);

		$this->assertSame('anja.bakker', $context['inspectorId']);
		$this->assertSame('ZAAK-2026-000147', $context['caseRef']);
		$this->assertSame('tablet-anja-001', $context['deviceId']);
		$this->assertSame('2026-05-22T09:15:32+00:00', $context['capturedAt']);
	}//end testBuildExifContext()

	/**
	 * Photo size validation enforces the 2 MB target.
	 *
	 * @return void
	 */
	public function testPhotoWithinTarget(): void {
		$this->assertTrue($this->service->isPhotoWithinTarget(1800000));
		$this->assertTrue($this->service->isPhotoWithinTarget(EvidenceMetadataService::MAX_PHOTO_BYTES));
		$this->assertFalse($this->service->isPhotoWithinTarget((EvidenceMetadataService::MAX_PHOTO_BYTES + 1)));
		$this->assertFalse($this->service->isPhotoWithinTarget(0));
	}//end testPhotoWithinTarget()

	/**
	 * Voice-memo duration validation enforces the 5-minute limit.
	 *
	 * @return void
	 */
	public function testVoiceMemoWithinLimit(): void {
		$this->assertTrue($this->service->isVoiceMemoWithinLimit(154));
		$this->assertTrue($this->service->isVoiceMemoWithinLimit(300));
		$this->assertFalse($this->service->isVoiceMemoWithinLimit(301));
		$this->assertFalse($this->service->isVoiceMemoWithinLimit(0));
	}//end testVoiceMemoWithinLimit()

	/**
	 * A voice-memo payload defaults transcriptionStatus to pending.
	 *
	 * @return void
	 */
	public function testBuildVoiceMemoPayloadPending(): void {
		$payload = $this->service->buildEvidencePayload(
			'inspect-1',
			'voice_memo',
			['localBlobRef' => 'blob:1', 'capturedAt' => '2026-05-22T09:20:00+00:00'],
			null,
			['lat' => 52.16, 'lon' => 5.38, 'accuracy' => 8.0]
		);

		$this->assertSame('voice_memo', $payload['type']);
		$this->assertSame('pending', $payload['transcriptionStatus']);
		$this->assertSame('internal', $payload['sensitivityLevel']);
		$this->assertNull($payload['cloudUrl']);
		$this->assertSame(8.0, $payload['gpsLocation']['accuracy']);
		$this->assertSame(52.16, $payload['gpsLocation']['lat']);
	}//end testBuildVoiceMemoPayloadPending()

	/**
	 * A photo payload defaults transcriptionStatus to not_applicable and
	 * honours an explicit sensitivity override.
	 *
	 * @return void
	 */
	public function testBuildPhotoPayloadNotApplicable(): void {
		$payload = $this->service->buildEvidencePayload(
			'inspect-1',
			'photo',
			['localBlobRef' => 'blob:2', 'sensitivityLevel' => 'confidential', 'tags' => ['fundering']],
			['lat' => 52.10, 'lon' => 5.30],
			null
		);

		$this->assertSame('photo', $payload['type']);
		$this->assertSame('not_applicable', $payload['transcriptionStatus']);
		$this->assertSame('confidential', $payload['sensitivityLevel']);
		$this->assertSame(['fundering'], $payload['tags']);
		// Sensorless fallback because no reading was supplied.
		$this->assertSame(52.10, $payload['gpsLocation']['lat']);
	}//end testBuildPhotoPayloadNotApplicable()
}//end class
