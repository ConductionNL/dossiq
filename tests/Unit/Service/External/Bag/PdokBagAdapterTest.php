<?php

/**
 * An instance with no Kadaster key can still check an address.
 *
 * `BagAdapterInterface` had two implementations: the paid Kadaster Bevragingen
 * v2, and a dormant one that logs the intention. So on every instance that has
 * not bought a Kadaster key, which is every instance today,
 * `LocationBagValidationListener` asks whether an address exists, is answered
 * LOOKUP_DEFERRED, and accepts whatever a handler typed.
 *
 * `PdokBagService` has spoken the free PDOK BAG WFS mirror since 2026-05-19
 * with no caller of any kind. This suite is about the adapter that gives it
 * one.
 *
 * 🔴 WHAT IS ASSERTED HERE IS THE VERDICT, NOT THE FIELD NAMES. FOUND,
 * NOT_FOUND and LOOKUP_ERROR are what the listener acts on, and they are
 * grounded in what the two PDOK clients return. The mapped address fields are
 * NOT verified against a live PDOK response, because this repository holds no
 * recorded one; `BagResponseMapper` yields null for anything it does not
 * recognise, so an unrecognised field degrades to an absent value rather than a
 * wrong one. Recording one WFS response as a fixture is what would finish it,
 * and claiming coverage of the mapping here would stop anyone doing that.
 *
 * MUTATION-CHECKED 2026-09-18: answering NOT_FOUND instead of LOOKUP_ERROR for
 * an unreachable PDOK reddens testAnUnreachablePdokIsNotAnAbsentBuilding, and
 * reading an absent Locatieserver envelope as an empty result reddens
 * testAnUnreachableLocatieserverIsNotAnAbsentAddress. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Bag
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
 * @spec openspec/specs/pdok-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Bag;

use OCA\Dossiq\Service\External\Bag\BagAdapterInterface;
use OCA\Dossiq\Service\External\Bag\BagResponseMapper;
use OCA\Dossiq\Service\External\Bag\PdokBagAdapter;
use OCA\Dossiq\Service\Pdok\PdokBagService;
use OCA\Dossiq\Service\Pdok\PdokLocatieserverService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The free BAG mirror, behind the BAG port.
 *
 * @covers \OCA\Dossiq\Service\External\Bag\PdokBagAdapter
 * @uses \OCA\Dossiq\Service\External\Bag\BagLookupResult
 * @uses \OCA\Dossiq\Service\External\Bag\BagResponseMapper
 *
 * @spec openspec/specs/pdok-integration/spec.md
 */
class PdokBagAdapterTest extends TestCase {

	/**
	 * It is an implementation of the port, not a fourth PDOK client.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testItSpeaksTheBagPort(): void {
		self::assertInstanceOf(
			expected: BagAdapterInterface::class,
			actual: $this->adapter(),
			message: 'The listener and the controller both depend on the port, not on a service.',
		);
		self::assertFalse(
			condition: $this->adapter()->isDormant(),
			message: 'It calls PDOK, so it must not claim to be dormant: a dormant answer is not checked.',
		);
	}//end testItSpeaksTheBagPort()

	/**
	 * A nummeraanduiding PDOK holds comes back as FOUND.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnObjectPdokHoldsIsFound(): void {
		$bag = $this->createMock(originalClassName: PdokBagService::class);
		$bag->expects(self::once())
			->method('getNummeraanduiding')
			->with('0363200000218908')
			->willReturn(['postcode' => '1016GV', 'huisnummer' => 12, 'bouwjaar' => 1904]);

		$result = $this->adapter(bag: $bag)
			->lookupObject(objectType: 'nummeraanduiding', id: '0363200000218908');

		self::assertSame(expected: 'FOUND', actual: $result->lookupStatus);
		self::assertSame(expected: '1016GV', actual: $result->address['postcode']);
	}//end testAnObjectPdokHoldsIsFound()

	/**
	 * A nummeraanduiding PDOK does not hold comes back as NOT_FOUND.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnObjectPdokDoesNotHoldIsNotFound(): void {
		$bag = $this->createMock(originalClassName: PdokBagService::class);
		$bag->method('getPand')->willReturn([]);

		self::assertSame(
			expected: 'NOT_FOUND',
			actual: $this->adapter(bag: $bag)->lookupObject(objectType: 'pand', id: '0363100012185735')->lookupStatus,
		);
	}//end testAnObjectPdokDoesNotHoldIsNotFound()

	/**
	 * A PDOK that cannot be reached is not a building that does not exist.
	 *
	 * THE ASSERTION THIS ADAPTER EXISTS FOR. `LocationBagValidationListener`
	 * rejects a location it is told does not exist, so answering NOT_FOUND
	 * during an outage would reject every address a handler typed for as long
	 * as it lasted, with a sentence saying the building is not there.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnUnreachablePdokIsNotAnAbsentBuilding(): void {
		$bag = $this->createMock(originalClassName: PdokBagService::class);
		$bag->method('getVerblijfsobject')->willThrowException(
			new RuntimeException('Network error contacting PDOK BAG WFS')
		);

		$result = $this->adapter(bag: $bag)
			->lookupObject(objectType: 'verblijfsobject', id: '0363010000740857');

		self::assertSame(
			expected: 'LOOKUP_ERROR',
			actual: $result->lookupStatus,
			message: 'PDOK did not answer. That is not the same statement as "there is no such building".',
		);
	}//end testAnUnreachablePdokIsNotAnAbsentBuilding()

	/**
	 * An object type the BAG does not have is refused before any call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnUnknownObjectTypeIsRefusedWithoutCalling(): void {
		$bag = $this->createMock(originalClassName: PdokBagService::class);
		$bag->expects(self::never())->method('getPand');

		self::assertSame(
			expected: 'INVALID_INPUT',
			actual: $this->adapter(bag: $bag)->lookupObject(objectType: 'perceel', id: '1')->lookupStatus,
		);
	}//end testAnUnknownObjectTypeIsRefusedWithoutCalling()

	/**
	 * A postcode and house number the Locatieserver knows come back as FOUND.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnAddressTheLocatieserverKnowsIsFound(): void {
		$locatieserver = $this->createMock(originalClassName: PdokLocatieserverService::class);
		$locatieserver->expects(self::once())
			->method('free')
			->with('1016GV 12A', ['type:adres'])
			->willReturn(['response' => ['numFound' => 1, 'docs' => [['postcode' => '1016GV']]]]);

		$result = $this->adapter(locatieserver: $locatieserver)->lookupAddress(
			postcode: '1016 gv',
			houseNumber: '12',
			huisletter: 'A',
		);

		self::assertSame(expected: 'FOUND', actual: $result->lookupStatus);
		self::assertSame(
			expected: 1,
			actual: $result->extras['count'],
			message: 'A postcode typed with a space and in lower case is the same postcode.',
		);
	}//end testAnAddressTheLocatieserverKnowsIsFound()

	/**
	 * A postcode and house number that exist nowhere come back as NOT_FOUND.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnAddressNobodyHasIsNotFound(): void {
		$locatieserver = $this->createMock(originalClassName: PdokLocatieserverService::class);
		$locatieserver->method('free')->willReturn(['response' => ['numFound' => 0, 'docs' => []]]);

		self::assertSame(
			expected: 'NOT_FOUND',
			actual: $this->adapter(locatieserver: $locatieserver)
				->lookupAddress(postcode: '1016GV', houseNumber: '9999')->lookupStatus,
		);
	}//end testAnAddressNobodyHasIsNotFound()

	/**
	 * A Locatieserver that did not answer is not an address that does not exist.
	 *
	 * The client returns `[]` when it could not call, and a real answer always
	 * carries the `response` envelope, empty or not. Reading the two the same
	 * way reports every outage as "no such address".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnUnreachableLocatieserverIsNotAnAbsentAddress(): void {
		$locatieserver = $this->createMock(originalClassName: PdokLocatieserverService::class);
		$locatieserver->method('free')->willReturn([]);

		self::assertSame(
			expected: 'LOOKUP_ERROR',
			actual: $this->adapter(locatieserver: $locatieserver)
				->lookupAddress(postcode: '1016GV', houseNumber: '12')->lookupStatus,
		);
	}//end testAnUnreachableLocatieserverIsNotAnAbsentAddress()

	/**
	 * An address with no house number is refused before any call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pdok-integration/spec.md
	 */
	public function testAnAddressWithNoHouseNumberIsRefusedWithoutCalling(): void {
		$locatieserver = $this->createMock(originalClassName: PdokLocatieserverService::class);
		$locatieserver->expects(self::never())->method('free');

		self::assertSame(
			expected: 'INVALID_INPUT',
			actual: $this->adapter(locatieserver: $locatieserver)
				->lookupAddress(postcode: '1016GV', houseNumber: '  ')->lookupStatus,
		);
	}//end testAnAddressWithNoHouseNumberIsRefusedWithoutCalling()

	/**
	 * The adapter under test, with the REAL response mapper.
	 *
	 * The mapper is not doubled: a doubled one would let an adapter that
	 * invented its own shape pass, and the whole point of the port is that
	 * every BAG answer arrives in one shape.
	 *
	 * @param PdokBagService|null           $bag           The BAG client double.
	 * @param PdokLocatieserverService|null $locatieserver The geocoder double.
	 *
	 * @return PdokBagAdapter The adapter.
	 */
	private function adapter(
		?PdokBagService $bag = null,
		?PdokLocatieserverService $locatieserver = null,
	): PdokBagAdapter {
		return new PdokBagAdapter(
			bag: ($bag ?? $this->createMock(originalClassName: PdokBagService::class)),
			locatieserver: ($locatieserver ?? $this->createMock(originalClassName: PdokLocatieserverService::class)),
			mapper: new BagResponseMapper(),
			logger: new NullLogger(),
		);
	}//end adapter()
}//end class
