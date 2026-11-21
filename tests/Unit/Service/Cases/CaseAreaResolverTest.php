<?php

/**
 * Where a case lies: its district and neighbourhood from the PDOK location server, or unplaced.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;


use DateTime;
use OCA\Dossiq\Service\Cases\CaseAreaResolver;
use OCA\Dossiq\Service\PdokService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The area a case is placed in, the unplaced answer, and when an area needs resolving again.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseAreaResolver
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseAreaResolverTest extends TestCase {

	/**
	 * The moment every answer is stamped with.
	 *
	 * @var string
	 */
	private const NOW = '2026-11-15T10:30:00+00:00';

	/**
	 * The PDOK lookup double.
	 *
	 * @var PdokService&MockObject
	 */
	private PdokService&MockObject $pdok;

	/**
	 * The resolver under test.
	 *
	 * @var CaseAreaResolver
	 */
	private CaseAreaResolver $resolver;

	/**
	 * Build the resolver over a PDOK double and a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->pdok = $this->createMock(originalClassName: PdokService::class);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime(self::NOW));

		$this->resolver = new CaseAreaResolver(pdok: $this->pdok, time: $time);
	}//end setUp()

	/**
	 * A found address places the case in its trimmed district and neighbourhood.
	 *
	 * @return void
	 */
	public function testAFoundAddressPlacesTheCase(): void {
		$this->pdok->expects(self::once())->method('lookupAddress')->with('adr-1')
			->willReturn(['wijknaam' => ' Centrum ', 'buurtnaam' => 'Binnenstad', 'weergavenaam' => 'Markt 1']);

		self::assertSame(
			[
				'district' => 'Centrum',
				'neighbourhood' => 'Binnenstad',
				'areaSource' => CaseAreaResolver::SOURCE_PDOK_LOCATIESERVER,
				'areaResolvedAt' => self::NOW,
				'areaFallbackUsed' => false,
			],
			$this->resolver->forAddressId(addressId: 'adr-1')
		);
	}//end testAFoundAddressPlacesTheCase()

	/**
	 * A failed lookup leaves the case unplaced, stamped, with the fallback flagged.
	 *
	 * @return void
	 */
	public function testAFailedLookupLeavesTheCaseUnplaced(): void {
		$this->pdok->method('lookupAddress')->willReturn(null);

		self::assertSame($this->unplaced(), $this->resolver->forAddressId(addressId: 'adr-unknown'));
	}//end testAFailedLookupLeavesTheCaseUnplaced()

	/**
	 * No address id is unplaced without asking PDOK.
	 *
	 * @return void
	 */
	public function testNoAddressIdDoesNotAskPdok(): void {
		$this->pdok->expects(self::never())->method('lookupAddress');

		self::assertSame($this->unplaced(), $this->resolver->forAddressId(addressId: ''));
	}//end testNoAddressIdDoesNotAskPdok()

	/**
	 * A document with only a neighbourhood still places the case; one with neither is unplaced.
	 *
	 * @return void
	 */
	public function testADocumentNeedsADistrictOrANeighbourhood(): void {
		$placed = $this->resolver->fromDocument(doc: ['buurtnaam' => 'Oost']);
		self::assertSame('', $placed['district']);
		self::assertSame('Oost', $placed['neighbourhood']);
		self::assertFalse($placed['areaFallbackUsed']);

		self::assertSame($this->unplaced(), $this->resolver->fromDocument(doc: ['wijknaam' => '  ', 'buurtnaam' => '']));
	}//end testADocumentNeedsADistrictOrANeighbourhood()

	/**
	 * An area is resolved again only for an address never resolved, or a different one.
	 *
	 * @return void
	 */
	public function testNeedsResolving(): void {
		self::assertFalse($this->resolver->needsResolving(case: [], addressId: ''));
		self::assertTrue($this->resolver->needsResolving(case: [], addressId: 'adr-1'));
		self::assertTrue($this->resolver->needsResolving(case: ['areaResolvedAt' => ' ', 'areaAddressId' => 'adr-1'], addressId: 'adr-1'));
		self::assertFalse($this->resolver->needsResolving(case: ['areaResolvedAt' => self::NOW, 'areaAddressId' => 'adr-1'], addressId: 'adr-1'));
		self::assertTrue($this->resolver->needsResolving(case: ['areaResolvedAt' => self::NOW, 'areaAddressId' => 'adr-1'], addressId: 'adr-2'));
	}//end testNeedsResolving()

	/**
	 * The unplaced answer at the fixed moment.
	 *
	 * @return array<string, mixed>
	 */
	private function unplaced(): array {
		return [
			'district' => '',
			'neighbourhood' => '',
			'areaSource' => CaseAreaResolver::SOURCE_PDOK_LOCATIESERVER,
			'areaResolvedAt' => self::NOW,
			'areaFallbackUsed' => true,
		];
	}//end unplaced()
}//end class
