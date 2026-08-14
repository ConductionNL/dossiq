<?php

/**
 * Unit tests for StufMessageBuilder outbound envelope construction.
 *
 * Exercises the outbound StUF 0310 envelope methods folded into
 * StufMessageBuilder (Lk01/Lk02/Lv01/Du01 + WSSE + ULID + payload limits).
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-outbound-envelope-construction
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Stuf;

use OCA\Procest\Service\Stuf\PayloadTooLargeException;
use OCA\Procest\Service\Stuf\StufResponseBuilder;
use OCA\Procest\Service\Stuf\StufVaultService;
use OCA\Procest\Service\Stuf\VrijBerichtNotRegisteredException;
use OCA\Procest\Service\Stuf\ZaaktypeNotMappedException;
use OCA\Procest\Service\StufMessageBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufMessageBuilder outbound methods.
 */
class StufMessageBuilderOutboundTest extends TestCase {
	private StufMessageBuilder $builder;

	/**
	 * @var StufVaultService&MockObject
	 */
	private StufVaultService $vault;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build endpoint fixture.
	 *
	 * @return array
	 */
	private function endpointFixture(): array {
		return [
			'id' => 'stuf-ep-test',
			'name' => 'Test',
			'recipientApplication' => 'Key2Zaken',
			'recipientOrganisation' => 'Gemeente Test',
			'recipientUser' => 'procest',
			'senderApplication' => 'Procest',
			'senderOrganisation' => 'Gemeente Test',
			'endpointUrl' => 'https://test.example/stuf',
			'soapVersion' => '1.1',
			'stufVersion' => '0310',
			'sectormodel' => 'ZKN',
			'authenticatie' => [
				'type' => 'wsse-usernametoken',
				'gebruikersnaam' => 'procest',
				'wachtwoordKluisRef' => 'vault://stuf/test',
			],
			'zaaktypeMappings' => [
				'evenementenvergunning' => 'Evenementenvergunning',
			],
			'freeMessagesTemplates' => [
				['name' => 'zetStatus', 'verplichteVelden' => ['zaakIdentificatie', 'statusType', 'datumStatusGezet']],
			],
		];
	}//end endpointFixture()

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->vault = $this->createMock(StufVaultService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->vault->method('resolveSecret')->willReturn('test-password');
		$this->builder = new StufMessageBuilder($this->logger, $this->vault);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testBuildLk01ContainsStuurgegevensAndZaaktype(): void {
		$envelope = $this->builder->buildLk01CreeerZaak(
			case: ['id' => 'case-1', 'type' => 'evenementenvergunning', 'omschrijving' => 'Tour de Amersfoort', 'startdatum' => '20260521'],
			endpoint: $this->endpointFixture()
		);
		$this->assertStringContainsString('<stuf:berichtcode>Lk01</stuf:berichtcode>', $envelope);
		$this->assertStringContainsString('<stuf:functie>creeerZaak</stuf:functie>', $envelope);
		$this->assertStringContainsString('<zkn:omschrijving>Evenementenvergunning</zkn:omschrijving>', $envelope);
		$this->assertStringContainsString('<stuf:tijdstipBericht>', $envelope);
		$this->assertStringContainsString('xmlns:zkn=', $envelope);
		$this->assertStringContainsString('xmlns:stuf=', $envelope);
		$this->assertStringContainsString('xmlns:bg=', $envelope);
		$this->assertStringContainsString('<wsse:Security>', $envelope);
		$this->assertStringContainsString('<wsse:Password>test-password</wsse:Password>', $envelope);
	}//end testBuildLk01ContainsStuurgegevensAndZaaktype()

	/**
	 * @return void
	 */
	public function testReferentienummerIsUnique(): void {
		$a = $this->builder->generateReferentienummer();
		$b = $this->builder->generateReferentienummer();
		$this->assertNotSame($a, $b);
		$this->assertSame(26, strlen($a));
	}//end testReferentienummerIsUnique()

	/**
	 * @return void
	 */
	public function testTijdstipBerichtFormat(): void {
		$ts = $this->builder->currentTimestampStuf();
		$this->assertMatchesRegularExpression('/^\d{17}$/', $ts);
	}//end testTijdstipBerichtFormat()

	/**
	 * @return void
	 */
	public function testZaaktypeNotMappedThrowsBeforeSend(): void {
		$this->expectException(ZaaktypeNotMappedException::class);
		$this->builder->buildLk01CreeerZaak(
			case: ['id' => 'case-1', 'type' => 'onbekend-type'],
			endpoint: $this->endpointFixture()
		);
	}//end testZaaktypeNotMappedThrowsBeforeSend()

	/**
	 * @return void
	 */
	public function testDocumentBase64EncodingIsCorrect(): void {
		$envelope = $this->builder->buildLk01CreeerZaak(
			case: [
				'id' => 'case-1',
				'type' => 'evenementenvergunning',
				'documenten' => [
					['name' => 'aanvraagformulier.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDFBYTES'],
				],
			],
			endpoint: $this->endpointFixture(),
			caseId: null,
			opts: ['includeDocuments' => true]
		);
		$expected = base64_encode('PDFBYTES');
		$this->assertStringContainsString('<stuf:bestandsnaam>aanvraagformulier.pdf</stuf:bestandsnaam>', $envelope);
		$this->assertStringContainsString('<stuf:bestandsinhoud>' . $expected . '</stuf:bestandsinhoud>', $envelope);
		// No line wrapping in the base64 output.
		$this->assertDoesNotMatchRegularExpression('#<stuf:bestandsinhoud>[^<]*\n[^<]*</stuf:bestandsinhoud>#', $envelope);
	}//end testDocumentBase64EncodingIsCorrect()

	/**
	 * @return void
	 */
	public function testPayloadTooLargeRejectsBeforeSend(): void {
		$this->expectException(PayloadTooLargeException::class);
		$this->builder->buildLk01CreeerZaak(
			case: [
				'id' => 'case-1',
				'type' => 'evenementenvergunning',
				'documenten' => [
					['name' => 'big.bin', 'mime' => 'application/octet-stream', 'bytes' => str_repeat('A', (40 * 1024 * 1024))],
				],
			],
			endpoint: $this->endpointFixture(),
			caseId: null,
			opts: ['includeDocuments' => true]
		);
	}//end testPayloadTooLargeRejectsBeforeSend()

	/**
	 * @return void
	 */
	public function testVrijBerichtRequiresRegisteredTemplate(): void {
		$this->expectException(VrijBerichtNotRegisteredException::class);
		$this->builder->buildDu01VrijBericht(name: 'doeIetsRaars', payload: [], endpoint: $this->endpointFixture());
	}//end testVrijBerichtRequiresRegisteredTemplate()

	/**
	 * @return void
	 */
	public function testVrijBerichtRequiresMandatoryFields(): void {
		$this->expectException(VrijBerichtNotRegisteredException::class);
		$this->builder->buildDu01VrijBericht(
			name: 'zetStatus',
			payload: ['zaakIdentificatie' => 'X'],
			endpoint: $this->endpointFixture()
		);
	}//end testVrijBerichtRequiresMandatoryFields()

	/**
	 * @return void
	 */
	public function testLv01ContainsScopeElements(): void {
		$envelope = $this->builder->buildLv01GeefDetails(
			caseId: 'ZAAK-2026-0008812',
			endpoint: $this->endpointFixture(),
			gewensteElementen: ['omschrijving', 'startdatum']
		);
		$this->assertStringContainsString('<stuf:berichtcode>Lv01</stuf:berichtcode>', $envelope);
		$this->assertStringContainsString('<zkn:identificatie>ZAAK-2026-0008812</zkn:identificatie>', $envelope);
		$this->assertStringContainsString('<zkn:omschrijving />', $envelope);
		$this->assertStringContainsString('<zkn:startdatum />', $envelope);
	}//end testLv01ContainsScopeElements()

	/**
	 * @return void
	 */
	public function testDu01GenereerZaakIdEnvelope(): void {
		$envelope = $this->builder->buildDu01GenereerZaakId(endpoint: $this->endpointFixture());
		$this->assertStringContainsString('<stuf:berichtcode>Du01</stuf:berichtcode>', $envelope);
		$this->assertStringContainsString('<stuf:functie>genereerZaakIdentificatie</stuf:functie>', $envelope);
		$this->assertStringContainsString('genereerZaakIdentificatie_Du01', $envelope);
	}//end testDu01GenereerZaakIdEnvelope()

	/**
	 * Inbound builder behaviour is preserved after the split: the responses
	 * procest returns as a StUF receiver now come from StufResponseBuilder,
	 * and must be byte-compatible with what StufMessageBuilder used to emit.
	 *
	 * @return void
	 */
	public function testInboundBuildersStillWork(): void {
		$responses = new StufResponseBuilder();

		$bv01 = $responses->buildBv01(['organisation' => 'Procest', 'applicatie' => 'Procest'], [], 'REF-123');
		$this->assertStringContainsString('Bv01', $bv01);
		$this->assertStringContainsString('REF-123', $bv01);
		$this->assertStringContainsString('<stuf:organisatie>Procest</stuf:organisatie>', $bv01);
		$this->assertStringContainsString('<stuf:crossRefnummer>REF-123</stuf:crossRefnummer>', $bv01);

		$fault = $responses->buildSoapFault('boom');
		$this->assertStringContainsString('soap:Fault', $fault);
		$this->assertStringContainsString('boom', $fault);

		$fo01 = $responses->buildFo01('StUF055', 'kapot', 'server', ['organisation' => 'Procest'], []);
		$this->assertStringContainsString('<stuf:code>StUF055</stuf:code>', $fo01);
		$this->assertStringContainsString('<stuf:plek>server</stuf:plek>', $fo01);
		$this->assertStringContainsString('<stuf:omschrijving>kapot</stuf:omschrijving>', $fo01);

		$stuurgegevens = $responses->buildStuurgegevens(['applicatie' => 'Procest'], [], 'REF-9');
		$this->assertStringContainsString('<stuf:berichtcode>Lk01</stuf:berichtcode>', $stuurgegevens);
		$this->assertStringContainsString('<stuf:referentienummer>REF-9</stuf:referentienummer>', $stuurgegevens);
	}//end testInboundBuildersStillWork()

	/**
	 * The inbound builders must NOT have been left behind on StufMessageBuilder:
	 * a duplicate would let the two directions drift apart silently.
	 *
	 * @return void
	 */
	public function testInboundBuildersAreNoLongerOnTheOutboundBuilder(): void {
		foreach (['buildBv01', 'buildFo01', 'buildSoapFault', 'buildSoapEnvelope', 'buildStuurgegevens'] as $method) {
			$this->assertFalse(
				condition: method_exists(StufMessageBuilder::class, $method),
				message: 'StufMessageBuilder still exposes the inbound builder "' . $method . '".'
			);
			$this->assertTrue(
				condition: method_exists(StufResponseBuilder::class, $method),
				message: 'StufResponseBuilder is missing the inbound builder "' . $method . '".'
			);
		}
	}//end testInboundBuildersAreNoLongerOnTheOutboundBuilder()
}//end class
