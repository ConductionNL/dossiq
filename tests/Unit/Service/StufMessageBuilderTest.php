<?php

/**
 * StufMessageBuilder Unit Tests
 *
 * The builder emits the StUF-ZKN envelopes procest sends to a municipal
 * ZS-DMS. What matters about a message on that wire is not that a method
 * returned a string, so these assert the parts a receiver actually rejects on:
 * the ZAAK entity type and functie, the mapped zaaktype-omschrijving, a
 * referentienummer of the right shape and alphabet, a 17-character
 * tijdstipBericht, and the two refusals — an unmapped case type, and a
 * document payload over the size limit.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Stuf\StufVaultService;
use OCA\Procest\Service\Stuf\ZaaktypeNotMappedException;
use OCA\Procest\Service\StufMessageBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for StufMessageBuilder.
 *
 * @covers \OCA\Procest\Service\StufMessageBuilder
 */
class StufMessageBuilderTest extends TestCase {

	/**
	 * Builder under test.
	 *
	 * @var StufMessageBuilder
	 */
	private StufMessageBuilder $builder;

	/**
	 * A minimal endpoint with one zaaktype mapping.
	 *
	 * @var array<string, mixed>
	 */
	private const ENDPOINT = [
		'id' => 'endpoint-1',
		// Flat keys, as the builder reads them — a nested zender/ontvanger
		// shape silently produces EMPTY organisatie/applicatie elements rather
		// than failing, which is precisely why the routing quartet is asserted
		// below instead of assumed.
		'zenderOrganisatie' => 'Procest',
		'zenderApplicatie' => 'PROCEST',
		'ontvangerOrganisatie' => 'Gemeente',
		'ontvangerApplicatie' => 'ZSDMS',
		'zaaktypeMappings' => ['melding-openbare-ruimte' => 'MOR'],
	];

	/**
	 * Build the service with stubbed collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->builder = new StufMessageBuilder(
			new NullLogger(),
			$this->createMock(StufVaultService::class)
		);
	}//end setUp()

	/**
	 * A mapped case yields a well-formed Lk01 carrying the ZAAK entity type,
	 * the creeerZaak functie and the MAPPED omschrijving — not the procest
	 * case type. Sending the local type would be rejected by the receiver.
	 *
	 * @return void
	 */
	public function testLk01CarriesTheMappedZaaktypeAndZaakEntity(): void {
		$xml = $this->builder->buildLk01CreeerZaak(
			case: ['type' => 'melding-openbare-ruimte', 'title' => 'Kapotte stoeptegel'],
			endpoint: self::ENDPOINT
		);

		$this->assertNotSame('', $xml);
		$this->assertStringContainsString('ZAK', $xml);
		$this->assertStringContainsString('creeerZaak', $xml);
		$this->assertStringContainsString('MOR', $xml, 'the MAPPED omschrijving must be on the wire');
		$this->assertStringNotContainsString(
			'<omschrijving>melding-openbare-ruimte<',
			$xml,
			"procest's own case type must never be sent as the zaaktype"
		);
	}//end testLk01CarriesTheMappedZaaktypeAndZaakEntity()

	/**
	 * The emitted envelope is parseable XML. A builder that concatenates
	 * strings can produce something that looks right in a substring assertion
	 * and still fails at the receiver's parser.
	 *
	 * @return void
	 */
	public function testLk01IsWellFormedXml(): void {
		$xml = $this->builder->buildLk01CreeerZaak(
			case: ['type' => 'melding-openbare-ruimte', 'title' => 'Kapotte stoeptegel'],
			endpoint: self::ENDPOINT
		);

		$prev = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$this->assertNotFalse($doc, 'the Lk01 envelope must parse as XML');
	}//end testLk01IsWellFormedXml()

	/**
	 * An unmapped case type is REFUSED rather than sent with an empty or
	 * guessed zaaktype. A message the receiver cannot route is worse than one
	 * that was never built, because the failure surfaces on their side.
	 *
	 * @return void
	 */
	public function testUnmappedCaseTypeIsRefused(): void {
		$this->expectException(ZaaktypeNotMappedException::class);
		$this->expectExceptionMessageMatches('/No zaaktype mapping/');

		$this->builder->buildLk01CreeerZaak(
			case: ['type' => 'iets-heel-anders'],
			endpoint: self::ENDPOINT
		);
	}//end testUnmappedCaseTypeIsRefused()

	/**
	 * The referentienummer is 26 characters from the Crockford-style alphabet
	 * the builder declares — no I, L, O or U, which is the point of choosing
	 * it (they are the characters humans mis-transcribe).
	 *
	 * @return void
	 */
	public function testReferentienummerShapeAndAlphabet(): void {
		$ref = $this->builder->generateReferentienummer();

		$this->assertSame(26, strlen($ref));
		$this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ref);
	}//end testReferentienummerShapeAndAlphabet()

	/**
	 * Two referentienummers in a row differ. A colliding reference would make
	 * a receiver treat a new message as a replay of an earlier one.
	 *
	 * @return void
	 */
	public function testReferentienummersAreDistinct(): void {
		$seen = [];
		for ($i = 0; $i < 25; $i++) {
			$seen[] = $this->builder->generateReferentienummer();
		}

		$this->assertCount(25, array_unique($seen));
	}//end testReferentienummersAreDistinct()

	/**
	 * tijdstipBericht is StUF's yyyyMMddHHmmssSSS — exactly 17 digits. A
	 * shorter or ISO-shaped value is rejected on receipt.
	 *
	 * @return void
	 */
	public function testTimestampIsSeventeenDigitStufFormat(): void {
		$ts = $this->builder->currentTimestampStuf();

		$this->assertSame(17, strlen($ts));
		$this->assertMatchesRegularExpression('/^\d{17}$/', $ts);
	}//end testTimestampIsSeventeenDigitStufFormat()

	/**
	 * Documents are only embedded when asked for. The default keeps the
	 * envelope small, which is what the payload limit exists to protect.
	 *
	 * @return void
	 */
	public function testDocumentsAreOmittedUnlessRequested(): void {
		$case = [
			'type' => 'melding-openbare-ruimte',
			'documenten' => [['name' => 'foto.jpg', 'mime' => 'image/jpeg', 'bytes' => 'AAAA']],
		];

		$without = $this->builder->buildLk01CreeerZaak(case: $case, endpoint: self::ENDPOINT);
		$this->assertStringNotContainsString('foto.jpg', $without);

		$with = $this->builder->buildLk01CreeerZaak(
			case: $case,
			endpoint: self::ENDPOINT,
			caseId: null,
			opts: ['includeDocuments' => true]
		);
		$this->assertStringContainsString('foto.jpg', $with);
	}//end testDocumentsAreOmittedUnlessRequested()

	/**
	 * A payload over the limit is REFUSED rather than truncated. Truncating
	 * would send a document the receiver stores as complete.
	 *
	 * @return void
	 */
	public function testOversizedDocumentPayloadIsRefused(): void {
		$this->expectException(\Throwable::class);

		$this->builder->buildLk01CreeerZaak(
			case: [
				'type' => 'melding-openbare-ruimte',
				'documenten' => [['name' => 'groot.bin', 'mime' => 'application/octet-stream', 'bytes' => str_repeat('A', 4096)]],
			],
			endpoint: self::ENDPOINT,
			caseId: null,
			opts: ['includeDocuments' => true, 'payloadLimitBytes' => 1024]
		);
	}//end testOversizedDocumentPayloadIsRefused()

	/**
	 * The Lv01 detail query carries the zaak id it is asking about.
	 *
	 * @return void
	 */
	public function testLv01CarriesTheRequestedZaakId(): void {
		$xml = $this->builder->buildLv01GeefDetails(caseId: 'ZAAK-4711', endpoint: self::ENDPOINT);

		$this->assertStringContainsString('ZAAK-4711', $xml);
		$this->assertStringContainsString('Lv01', $xml);
	}//end testLv01CarriesTheRequestedZaakId()

	/**
	 * Outbound stuurgegevens carry the sender and receiver the endpoint
	 * declares, plus the berichtcode — the routing quartet a ZS-DMS matches on.
	 *
	 * @return void
	 */
	public function testOutboundStuurgegevensCarryTheRoutingQuartet(): void {
		$xml = $this->builder->buildOutboundStuurgegevens(
			messageCode: 'Lk01',
			endpoint: self::ENDPOINT,
			entiteittype: 'ZAK',
			role: 'creeerZaak',
			referentienummer: $this->builder->generateReferentienummer(),
			momentMessage: $this->builder->currentTimestampStuf()
		);

		$this->assertStringContainsString('PROCEST', $xml);
		$this->assertStringContainsString('ZSDMS', $xml);
		$this->assertStringContainsString('Lk01', $xml);
		$this->assertStringContainsString('ZAK', $xml);
	}//end testOutboundStuurgegevensCarryTheRoutingQuartet()
}//end class
