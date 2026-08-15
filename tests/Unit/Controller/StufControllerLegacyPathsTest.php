<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the deprecated Dutch StUF path aliases.
 *
 * Three URLs survive their rename because they are WIRE CONTRACTS held in the
 * SENDING zaaksysteem's configuration, not in ours:
 *
 *     /api/stuf/zaken     -> casesLegacyPath()   -> cases()
 *     /api/stuf/personen  -> personsLegacyPath() -> persons()
 *     /api/stuf/inkomend  -> inboundLegacyPath() -> inbound()
 *
 * Each needs its OWN method because openregister's AppHost
 * Routes::standard() rejects duplicate route names by `name` alone and never
 * reads `postfix` — the two-entries-one-name form throws at boot and takes the
 * whole app's routing down. Measured: it failed procest's E2E seed.
 *
 * A delegation that silently stopped delegating is the failure this file
 * exists for. It would not throw and would not fail a route test; the old URL
 * would simply answer with something else, on a municipality's schedule.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\StufController;
use OCA\Procest\Service\Stuf\StufEnvelopeInspector;
use OCA\Procest\Service\Stuf\StufServices;
use OCA\Procest\Service\Stuf\StufSoapRequestDispatcher;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The Dutch path aliases delegate to their English methods.
 *
 * @covers \OCA\Procest\Controller\StufController
 */
class StufControllerLegacyPathsTest extends TestCase {

	/**
	 * @var StufSoapRequestDispatcher|MockObject
	 */
	private $dispatcher;


	/**
	 * Set up the dispatcher double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(StufSoapRequestDispatcher::class);

	}//end setUp()


	/**
	 * Build the controller with a pinned request body.
	 *
	 * @return StufController
	 */
	private function controller(): StufController {
		$services = (new ReflectionClass(StufServices::class))->newInstanceWithoutConstructor();

		return new class(
			'procest',
			$this->createMock(IRequest::class),
			$services,
			$this->dispatcher,
			$this->createMock(StufEnvelopeInspector::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
		) extends StufController {

			/**
			 * Serve a fixed body instead of php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return '<soap:Envelope/>';

			}//end readRawBody()


		};

	}//end controller()


	/**
	 * /api/stuf/zaken reaches the same dispatch as /api/stuf/cases.
	 *
	 * The service constant is asserted, not just the call: passing the WRONG
	 * service would still dispatch, still return 200, and route StUF-ZKN
	 * traffic through the person handler.
	 *
	 * @return void
	 */
	public function testTheDutchCasesPathDispatchesAsCases(): void {
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with('<soap:Envelope/>', StufSoapRequestDispatcher::SERVICE_CASES)
			->willReturn(new DataDisplayResponse('<ok/>'));

		$this->controller()->casesLegacyPath();

	}//end testTheDutchCasesPathDispatchesAsCases()


	/**
	 * /api/stuf/personen reaches the same dispatch as /api/stuf/persons.
	 *
	 * @return void
	 */
	public function testTheDutchPersonsPathDispatchesAsPersons(): void {
		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with('<soap:Envelope/>', StufSoapRequestDispatcher::SERVICE_PERSONS)
			->willReturn(new DataDisplayResponse('<ok/>'));

		$this->controller()->personsLegacyPath();

	}//end testTheDutchPersonsPathDispatchesAsPersons()


	/**
	 * The two services stay distinguishable.
	 *
	 * If they ever collapsed to one value, both aliases above would pass while
	 * every StUF-BG message was handled as a case.
	 *
	 * @return void
	 */
	public function testTheTwoServicesAreNotTheSameValue(): void {
		$this->assertNotSame(
			StufSoapRequestDispatcher::SERVICE_CASES,
			StufSoapRequestDispatcher::SERVICE_PERSONS
		);

	}//end testTheTwoServicesAreNotTheSameValue()


	/**
	 * The service discriminators are English.
	 *
	 * They are safe to hold in English precisely because they never reach the
	 * wire — the sending endpoint is resolved from the envelope's `zender`, and
	 * the only other use is log context. The statutory ZGW REST resource
	 * `zaken` is a different string in a different place and stays Dutch.
	 *
	 * @return void
	 */
	public function testTheServiceDiscriminatorsAreEnglish(): void {
		$this->assertSame('cases', StufSoapRequestDispatcher::SERVICE_CASES);
		$this->assertSame('persons', StufSoapRequestDispatcher::SERVICE_PERSONS);

	}//end testTheServiceDiscriminatorsAreEnglish()


}//end class
