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
class StufMessageBuilderOutboundTest extends TestCase
{
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
    private function endpointFixture(): array
    {
        return [
            'id'                   => 'stuf-ep-test',
            'naam'                 => 'Test',
            'ontvangerApplicatie'  => 'Key2Zaken',
            'ontvangerOrganisatie' => 'Gemeente Test',
            'ontvangerGebruiker'   => 'procest',
            'zenderApplicatie'     => 'Procest',
            'zenderOrganisatie'    => 'Gemeente Test',
            'endpointUrl'          => 'https://test.example/stuf',
            'soapVersion'          => '1.1',
            'stufVersion'          => '0310',
            'sectormodel'          => 'ZKN',
            'authenticatie'        => [
                'type'               => 'wsse-usernametoken',
                'gebruikersnaam'     => 'procest',
                'wachtwoordKluisRef' => 'vault://stuf/test',
            ],
            'zaaktypeMappings'     => [
                'evenementenvergunning' => 'Evenementenvergunning',
            ],
            'vrijeBerichtenTemplates' => [
                ['naam' => 'zetStatus', 'verplichteVelden' => ['zaakIdentificatie', 'statusType', 'datumStatusGezet']],
            ],
        ];
    }//end endpointFixture()

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->vault  = $this->createMock(StufVaultService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->vault->method('resolveSecret')->willReturn('test-password');
        $this->builder = new StufMessageBuilder($this->logger, $this->vault);
    }//end setUp()

    /**
     * @return void
     */
    public function testBuildLk01ContainsStuurgegevensAndZaaktype(): void
    {
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
    public function testReferentienummerIsUnique(): void
    {
        $a = $this->builder->generateReferentienummer();
        $b = $this->builder->generateReferentienummer();
        $this->assertNotSame($a, $b);
        $this->assertSame(26, strlen($a));
    }//end testReferentienummerIsUnique()

    /**
     * @return void
     */
    public function testTijdstipBerichtFormat(): void
    {
        $ts = $this->builder->currentTimestampStuf();
        $this->assertMatchesRegularExpression('/^\d{17}$/', $ts);
    }//end testTijdstipBerichtFormat()

    /**
     * @return void
     */
    public function testZaaktypeNotMappedThrowsBeforeSend(): void
    {
        $this->expectException(ZaaktypeNotMappedException::class);
        $this->builder->buildLk01CreeerZaak(
            case: ['id' => 'case-1', 'type' => 'onbekend-type'],
            endpoint: $this->endpointFixture()
        );
    }//end testZaaktypeNotMappedThrowsBeforeSend()

    /**
     * @return void
     */
    public function testDocumentBase64EncodingIsCorrect(): void
    {
        $envelope = $this->builder->buildLk01CreeerZaak(
            case: [
                'id'         => 'case-1',
                'type'       => 'evenementenvergunning',
                'documenten' => [
                    ['name' => 'aanvraagformulier.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDFBYTES'],
                ],
            ],
            endpoint: $this->endpointFixture(),
            zaakId: null,
            opts: ['includeDocuments' => true]
        );
        $expected = base64_encode('PDFBYTES');
        $this->assertStringContainsString('<stuf:bestandsnaam>aanvraagformulier.pdf</stuf:bestandsnaam>', $envelope);
        $this->assertStringContainsString('<stuf:bestandsinhoud>'.$expected.'</stuf:bestandsinhoud>', $envelope);
        // No line wrapping in the base64 output.
        $this->assertDoesNotMatchRegularExpression('#<stuf:bestandsinhoud>[^<]*\n[^<]*</stuf:bestandsinhoud>#', $envelope);
    }//end testDocumentBase64EncodingIsCorrect()

    /**
     * @return void
     */
    public function testPayloadTooLargeRejectsBeforeSend(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->builder->buildLk01CreeerZaak(
            case: [
                'id'         => 'case-1',
                'type'       => 'evenementenvergunning',
                'documenten' => [
                    ['name' => 'big.bin', 'mime' => 'application/octet-stream', 'bytes' => str_repeat('A', (40 * 1024 * 1024))],
                ],
            ],
            endpoint: $this->endpointFixture(),
            zaakId: null,
            opts: ['includeDocuments' => true]
        );
    }//end testPayloadTooLargeRejectsBeforeSend()

    /**
     * @return void
     */
    public function testVrijBerichtRequiresRegisteredTemplate(): void
    {
        $this->expectException(VrijBerichtNotRegisteredException::class);
        $this->builder->buildDu01VrijBericht(name: 'doeIetsRaars', payload: [], endpoint: $this->endpointFixture());
    }//end testVrijBerichtRequiresRegisteredTemplate()

    /**
     * @return void
     */
    public function testVrijBerichtRequiresMandatoryFields(): void
    {
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
    public function testLv01ContainsScopeElements(): void
    {
        $envelope = $this->builder->buildLv01GeefDetails(
            zaakId: 'ZAAK-2026-0008812',
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
    public function testDu01GenereerZaakIdEnvelope(): void
    {
        $envelope = $this->builder->buildDu01GenereerZaakId(endpoint: $this->endpointFixture());
        $this->assertStringContainsString('<stuf:berichtcode>Du01</stuf:berichtcode>', $envelope);
        $this->assertStringContainsString('<stuf:functie>genereerZaakIdentificatie</stuf:functie>', $envelope);
        $this->assertStringContainsString('genereerZaakIdentificatie_Du01', $envelope);
    }//end testDu01GenereerZaakIdEnvelope()

    /**
     * Inbound builder behaviour is preserved alongside the outbound methods.
     *
     * @return void
     */
    public function testInboundBuildersStillWork(): void
    {
        $bv01 = $this->builder->buildBv01(['organisatie' => 'Procest', 'applicatie' => 'Procest'], [], 'REF-123');
        $this->assertStringContainsString('Bv01', $bv01);
        $this->assertStringContainsString('REF-123', $bv01);

        $fault = $this->builder->buildSoapFault('boom');
        $this->assertStringContainsString('soap:Fault', $fault);
        $this->assertStringContainsString('boom', $fault);
    }//end testInboundBuildersStillWork()
}//end class
