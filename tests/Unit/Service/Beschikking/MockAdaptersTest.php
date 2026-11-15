<?php

/**
 * Unit tests for the mock signing and template-engine adapters: their ids are
 * deterministic in their inputs, distinct across inputs, and their payloads
 * carry the shape the beschikking pipeline reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Service\Beschikking\MockSigningAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\MockSigningAdapter
 * @covers \OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter
 */
class MockAdaptersTest extends TestCase {

	public function testSigningIdsAreDerivedFromFileSignatoryAndProvider(): void {
		$seed = 'f-1|alice|tsp-a';
		$signed = (new MockSigningAdapter())->sign('f-1', 'alice', 'tsp-a');

		$this->assertSame('signed-' . substr(hash('sha256', $seed), 0, 12), $signed['signedBestandId']);
		$this->assertSame('val-' . substr(hash('sha256', 'report' . $seed), 0, 12), $signed['validationRapportId']);
		$this->assertSame('0x' . substr(hash('sha256', 'cert' . $seed), 0, 16), $signed['certificateSerialNumber']);
		$this->assertSame('NL-TSP-0001', $signed['tspProviderEidasId']);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $signed['signingMoment']));
	}//end testSigningIdsAreDerivedFromFileSignatoryAndProvider()

	public function testADifferentSignatoryGivesADifferentSignedFile(): void {
		$adapter = new MockSigningAdapter();

		$this->assertNotSame(
			$adapter->sign('f-1', 'alice', 'tsp-a')['signedBestandId'],
			$adapter->sign('f-1', 'bob', 'tsp-a')['signedBestandId']
		);
	}//end testADifferentSignatoryGivesADifferentSignedFile()

	public function testTheValidationReportEchoesItsIdAndIsValid(): void {
		$report = (new MockSigningAdapter())->fetchValidationReport('val-123');

		$this->assertSame('val-123', $report['validationRapportId']);
		$this->assertSame('tsp-handtekening-rapport', $report['kind']);
		$this->assertSame('ETSI EN 319 102-1', $report['norm']);
		$this->assertTrue($report['valid']);
		$this->assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $report['gegenereerdOp']));
	}//end testTheValidationReportEchoesItsIdAndIsValid()

	public function testRenderingIsDeterministicInTemplateAndContext(): void {
		$payload = json_encode(['tpl-1', ['naam' => 'Één']], JSON_UNESCAPED_UNICODE);
		$rendered = (new MockTemplateEngineAdapter())->render('tpl-1', ['naam' => 'Één']);

		$this->assertSame([
			'format' => 'pdf-a3',
			'fileId' => 'doc-' . substr(hash('sha256', $payload), 0, 12),
			'checksumSha256' => hash('sha256', $payload),
			'paginas' => 4,
		], $rendered);
		$this->assertNotSame($rendered['fileId'], (new MockTemplateEngineAdapter())->render('tpl-1', ['naam' => 'Anders'])['fileId']);
	}//end testRenderingIsDeterministicInTemplateAndContext()

	public function testAnUnencodableContextFallsBackToTheTemplateId(): void {
		$rendered = (new MockTemplateEngineAdapter())->render('tpl-2', ['bad' => "\xB1\x31"]);

		$this->assertSame(hash('sha256', 'tpl-2'), $rendered['checksumSha256']);
		$this->assertSame('doc-' . substr(hash('sha256', 'tpl-2'), 0, 12), $rendered['fileId']);
	}//end testAnUnencodableContextFallsBackToTheTemplateId()

	public function testResolveVersionPinsV1AtTheEffectiveDate(): void {
		$this->assertSame(
			['templateId' => 'tpl-3', 'version' => 'v1', 'effectiveDate' => '2026-01-01'],
			(new MockTemplateEngineAdapter())->resolveVersion('tpl-3', '2026-01-01')
		);
	}//end testResolveVersionPinsV1AtTheEffectiveDate()
}//end class
