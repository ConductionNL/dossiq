<?php

/**
 * StufController Wire-Contract Tests
 *
 * Contract coverage for the two inbound SOAP receivers (gate-25):
 * `POST /api/stuf/cases` (StUF-ZKN) and `POST /api/stuf/persons` (StUF-BG).
 * Both are `#[PublicPage]` + `#[NoCSRFRequired]` — a municipal middleware
 * component posts to them with no Nextcloud session — and both bodies are a
 * single delegation to `StufSoapRequestDispatcher::dispatch()`. When two
 * near-identical one-line methods differ only in a constant, the realistic
 * defect is that constant, so that is what these tests pin:
 *
 *  - `cases()` dispatches with SERVICE_CASES and `persons()` with
 *    SERVICE_PERSONS. A copy-paste that routes person messages through the
 *    zaken handler would still return a well-formed SOAP envelope and still
 *    answer 200 — nothing downstream would look broken;
 *  - the two constants are genuinely different values, so the assertions above
 *    can actually discriminate;
 *  - the dispatcher's response is returned VERBATIM: the same object, the same
 *    SOAP XML body and the same `text/xml` content type. Re-wrapping it (for
 *    example into a JSONResponse) would break the wire contract the sending
 *    zaaksysteem parses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for StufController::cases() and ::persons().
 *
 * @covers \OCA\Dossiq\Controller\StufController
 */
class StufControllerContractTest extends TestCase {

	/**
	 * A minimal SOAP reply, as the dispatcher would produce it.
	 */
	private const SOAP_REPLY = '<?xml version="1.0"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
		. '<soap:Body><stuf:Bv03 xmlns:stuf="http://www.egem.nl/StUF/StUF0301"/></soap:Body>'
		. '</soap:Envelope>';

	/**
	 * The inbound SOAP dispatcher.
	 *
	 * @var StufSoapRequestDispatcher|MockObject
	 */
	private StufSoapRequestDispatcher $dispatcher;

	/**
	 * The controller under test.
	 *
	 * @var StufController
	 */
	private StufController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dispatcher = $this->createMock(StufSoapRequestDispatcher::class);

		$this->controller = new StufController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			stuf: $this->createMock(StufServices::class),
			dispatcher: $this->dispatcher,
			inspector: $this->createMock(StufEnvelopeInspector::class),
			l10n: $this->createMock(IL10N::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The two service constants are distinct, so the delegation assertions
	 * below can actually tell the two receivers apart.
	 *
	 * @return void
	 */
	public function testTheZakenAndPersonenServiceDiscriminatorsAreDistinct(): void {
		$this->assertNotSame(
			StufSoapRequestDispatcher::SERVICE_CASES,
			StufSoapRequestDispatcher::SERVICE_PERSONS,
			'a single shared constant would make both receivers indistinguishable'
		);
	}//end testTheZakenAndPersonenServiceDiscriminatorsAreDistinct()

	/**
	 * `/api/stuf/cases` dispatches to the ZKN (cases) handler.
	 *
	 * @return void
	 */
	public function testCasesDispatchesToTheCasesServiceAndReturnsTheEnvelopeVerbatim(): void {
		$expected = new DataDisplayResponse(self::SOAP_REPLY, Http::STATUS_OK, ['Content-Type' => 'text/xml']);

		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with($this->anything(), StufSoapRequestDispatcher::SERVICE_CASES)
			->willReturn($expected);

		$response = $this->controller->cases();

		$this->assertSame($expected, $response);
		$this->assertSame(self::SOAP_REPLY, $response->render());
		$this->assertSame('text/xml', $response->getHeaders()['Content-Type']);
	}//end testCasesDispatchesToTheCasesServiceAndReturnsTheEnvelopeVerbatim()

	/**
	 * `/api/stuf/persons` dispatches to the BG (persons) handler.
	 *
	 * @return void
	 */
	public function testPersonsDispatchesToThePersonsServiceAndReturnsTheEnvelopeVerbatim(): void {
		$expected = new DataDisplayResponse(self::SOAP_REPLY, Http::STATUS_OK, ['Content-Type' => 'text/xml']);

		$this->dispatcher->expects($this->once())
			->method('dispatch')
			->with($this->anything(), StufSoapRequestDispatcher::SERVICE_PERSONS)
			->willReturn($expected);

		$response = $this->controller->persons();

		$this->assertSame($expected, $response);
		$this->assertSame(self::SOAP_REPLY, $response->render());
	}//end testPersonsDispatchesToThePersonsServiceAndReturnsTheEnvelopeVerbatim()

	/**
	 * A SOAP fault from the dispatcher is passed through with its own status,
	 * not rewritten into a 200 — the sender's retry logic reads the status.
	 *
	 * @return void
	 */
	public function testCasesPassesADispatcherFaultStatusThroughUnchanged(): void {
		$fault = new DataDisplayResponse(
			'<soap:Fault/>',
			Http::STATUS_BAD_REQUEST,
			['Content-Type' => 'text/xml']
		);

		$this->dispatcher->method('dispatch')->willReturn($fault);

		$response = $this->controller->cases();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('<soap:Fault/>', $response->render());
	}//end testCasesPassesADispatcherFaultStatusThroughUnchanged()
}//end class
