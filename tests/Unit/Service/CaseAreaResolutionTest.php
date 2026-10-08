<?php

/**
 * Resolving a case's wijk and buurt, and saying where the answer came from.
 *
 * 🔴 AN ADDRESS THAT PLACED IN NOTHING AND AN ADDRESS NOBODY LOOKED UP MUST
 * NOT LOOK THE SAME. Both leave the wijk empty; only the timestamp and the
 * flag tell them apart, and only one of them is a case an administrator should
 * go and look at. Every unplaced path is therefore asserted to be STAMPED.
 *
 * 🔴 THE ANSWER CARRIES ITS SOURCE, because a wijk on a case from last year
 * can only be explained beside the boundary set it was read from. Today that
 * is PDOK's locatieserver; when integriq publishes `pdok-geo-boundaries` the
 * value says which one placed the case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTime;
use OCA\Dossiq\Service\Cases\CaseAreaResolver;
use OCA\Dossiq\Service\PdokService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The area resolution.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class CaseAreaResolutionTest extends TestCase {
	/**
	 * A resolver over a lookup we dictate.
	 *
	 * @param array<string, mixed>|null $doc What PDOK answers.
	 *
	 * @return CaseAreaResolver The resolver.
	 */
	private function resolver(?array $doc): CaseAreaResolver {
		$pdok = $this->createMock(PdokService::class);
		$pdok->method('lookupAddress')->willReturn($doc);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-18T10:00:00+02:00'));

		return new CaseAreaResolver($pdok, $time);
	}//end resolver()

	/**
	 * An address that places carries its wijk, its buurt and its source.
	 *
	 * @return void
	 */
	public function testAnAddressResolvesToItsWijkAndBuurt(): void {
		$area = $this->resolver(['wijknaam' => 'Zuid', 'buurtnaam' => 'Bloemenbuurt'])
			->forAddressId(addressId: 'adr-1');

		$this->assertSame('Zuid', $area['district']);
		$this->assertSame('Bloemenbuurt', $area['neighbourhood']);
		$this->assertSame(CaseAreaResolver::SOURCE_PDOK_LOCATIESERVER, $area['areaSource']);
		$this->assertSame('2026-09-18T10:00:00+02:00', $area['areaResolvedAt']);
		$this->assertFalse($area['areaFallbackUsed']);
	}//end testAnAddressResolvesToItsWijkAndBuurt()

	/**
	 * An address that places in nothing is still stamped, and says so.
	 *
	 * @return void
	 */
	public function testAnAddressOutsideEveryBoundaryIsStampedAndFlagged(): void {
		$area = $this->resolver(['straatnaam' => 'Ergens'])->forAddressId(addressId: 'adr-2');

		$this->assertSame('', $area['district']);
		$this->assertTrue($area['areaFallbackUsed']);
		// Stamped: a case that was looked at and could not be placed is a
		// different fact from a case nobody has looked at.
		$this->assertSame('2026-09-18T10:00:00+02:00', $area['areaResolvedAt']);
	}//end testAnAddressOutsideEveryBoundaryIsStampedAndFlagged()

	/**
	 * A lookup that failed does not guess a wijk.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsUnplacedRatherThanWrong(): void {
		$area = $this->resolver(null)->forAddressId(addressId: 'adr-3');

		$this->assertSame('', $area['district']);
		$this->assertTrue($area['areaFallbackUsed']);
	}//end testAFailedLookupIsUnplacedRatherThanWrong()

	/**
	 * A case with no address at all is unplaced, and asks PDOK nothing.
	 *
	 * @return void
	 */
	public function testACaseWithNoAddressAsksNothing(): void {
		$pdok = $this->createMock(PdokService::class);
		$pdok->expects($this->never())->method('lookupAddress');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-18T10:00:00+02:00'));

		$area = (new CaseAreaResolver($pdok, $time))->forAddressId(addressId: '');

		$this->assertTrue($area['areaFallbackUsed']);
	}//end testACaseWithNoAddressAsksNothing()

	/**
	 * A buurt with no wijk still places the case.
	 *
	 * @return void
	 */
	public function testABuurtWithoutAWijkStillPlacesTheCase(): void {
		$area = $this->resolver(null)->fromDocument(doc: ['buurtnaam' => 'Bloemenbuurt']);

		$this->assertSame('Bloemenbuurt', $area['neighbourhood']);
		$this->assertFalse($area['areaFallbackUsed']);
	}//end testABuurtWithoutAWijkStillPlacesTheCase()

	/**
	 * The resolution runs when the ADDRESS changes, not on every write.
	 *
	 * @return void
	 */
	public function testItResolvesAgainOnlyWhenTheAddressChanged(): void {
		$resolver = $this->resolver(null);
		$resolved = ['areaResolvedAt' => '2026-01-01T00:00:00+01:00', 'areaAddressId' => 'adr-1'];

		$this->assertFalse($resolver->needsResolving(case: $resolved, addressId: 'adr-1'));
		$this->assertTrue($resolver->needsResolving(case: $resolved, addressId: 'adr-2'));
		$this->assertTrue($resolver->needsResolving(case: [], addressId: 'adr-1'));
		// No address is nothing to resolve, rather than a reason to ask PDOK
		// about an empty string on every write.
		$this->assertFalse($resolver->needsResolving(case: [], addressId: ''));
	}//end testItResolvesAgainOnlyWhenTheAddressChanged()
}//end class
