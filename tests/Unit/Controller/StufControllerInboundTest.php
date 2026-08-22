<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the inbound StUF confirmation receiver.
 *
 * WHY THIS FILE EXISTS
 *
 * `StufController::inbound()` is a `#[PublicPage]` route. Nothing in
 * Nextcloud's middleware stack will ever refuse a caller on it, so the refusal
 * has to come from the controller — and until this suite, nothing watched it
 * refuse. A guard nobody has watched refuse is a guard nobody has tested:
 * deleting the `verifyWsse()` call left every test in the repository green.
 *
 * Its two sibling public routes, `zaken()` and `personen()`, already had that
 * cover in StufSoapRequestDispatcherAuthTest. This applies the same standard to
 * the third.
 *
 * The suite is written so that DELETING THE GUARD MAKES IT RED. Three arms
 * assert a refusal that only exists because a check runs, and one asserts that
 * a verified sender is still processed — so a controller that refused
 * *everything* would fail too. Without that last arm, `return 422` on the first
 * line would pass the other three.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufMessageHandler;
use OCA\Dossiq\Service\Stuf\StufMessageParser;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behavioural tests for StufController::inbound().
 *
 * @covers \OCA\Dossiq\Controller\StufController
 */
class StufControllerInboundTest extends TestCase {

	/**
	 * A minimal Bv01 confirmation envelope carrying a WSSE UsernameToken.
	 *
	 * The token values are deliberately present: an envelope with no token at
	 * all would be refused by a checker that merely looks for the element,
	 * which is a weaker guard than the one being tested.
	 */
	private const ENVELOPE = '<?xml version="1.0"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
		. ' xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"'
		. ' xmlns:stuf="http://www.egem.nl/StUF/StUF0301">'
		. '<soap:Header><wsse:Security><wsse:UsernameToken>'
		. '<wsse:Username>zaaksysteem</wsse:Username>'
		. '<wsse:Password>correct-horse</wsse:Password>'
		. '</wsse:UsernameToken></wsse:Security></soap:Header>'
		. '<soap:Body><stuf:bevestiging/></soap:Body></soap:Envelope>';

	/**
	 * A configured endpoint row, as resolveEndpoint() would return it.
	 *
	 * @var array<string,mixed>
	 */
	private const ENDPOINT = ['id' => 42, 'naam' => 'ZAAKSYSTEEM'];

	/**
	 * @var StufEnvelopeInspector|MockObject
	 */
	private $inspector;

	/**
	 * @var StufMessageHandler|MockObject
	 */
	private $messageHandler;

	/**
	 * @var StufMessageParser|MockObject
	 */
	private $parser;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->inspector      = $this->createMock(StufEnvelopeInspector::class);
		$this->messageHandler = $this->createMock(StufMessageHandler::class);
		$this->parser         = $this->createMock(StufMessageParser::class);

		$this->parser->method('parseBevestiging')->willReturn([]);
		$this->inspector->method('detectBerichtSoort')->willReturn('Bv01');
		$this->inspector->method('extractCrossRefnummer')->willReturn('xref-1');
		$this->inspector->method('extractFunctie')->willReturn('ontvanger');

	}//end setUp()


	/**
	 * Build the controller with the raw body pinned to a known string.
	 *
	 * The anonymous subclass overrides `readRawBody()`, the seam that exists
	 * because `php://input` cannot be driven from a unit test. That seam is the
	 * reason this endpoint went untested while its siblings did not.
	 *
	 * @param string $body The raw request body to serve.
	 *
	 * @return StufController
	 */
	private function controller(string $body): StufController {
		$services = $this->createMock(StufServices::class);

		// StufServices exposes its collaborators as readonly promoted
		// properties, so they are stubbed on the mock rather than injected.
		$reflection = new \ReflectionClass(StufServices::class);
		$instance   = $reflection->newInstanceWithoutConstructor();
		foreach (['messageHandler' => $this->messageHandler, 'parser' => $this->parser] as $name => $double) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($instance, $double);
		}

		unset($services);

		return new class(
			'dossiq',
			$this->createMock(IRequest::class),
			$instance,
			$this->createMock(StufSoapRequestDispatcher::class),
			$this->inspector,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$body,
		) extends StufController {

			/**
			 * @param string                     $appName    App id.
			 * @param IRequest                   $request    Request.
			 * @param StufServices               $stuf       Services.
			 * @param StufSoapRequestDispatcher  $dispatcher Dispatcher.
			 * @param StufEnvelopeInspector      $inspector  Inspector.
			 * @param IL10N                      $l10n       Translations.
			 * @param LoggerInterface            $logger     Logger.
			 * @param string                     $body       Raw body to serve.
			 */
			public function __construct(
				string $appName,
				IRequest $request,
				StufServices $stuf,
				StufSoapRequestDispatcher $dispatcher,
				StufEnvelopeInspector $inspector,
				IL10N $l10n,
				LoggerInterface $logger,
				private readonly string $body,
			) {
				parent::__construct(
					appName: $appName,
					request: $request,
					stuf: $stuf,
					dispatcher: $dispatcher,
					inspector: $inspector,
					l10n: $l10n,
					logger: $logger,
				);

			}//end __construct()


			/**
			 * Serve the pinned body instead of php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->body;

			}//end readRawBody()


		};

	}//end controller()


	/**
	 * A tampered WSSE token is refused with 422, and the envelope is never
	 * interpreted.
	 *
	 * This is THE arm the endpoint was missing. Delete the `verifyWsse()` call
	 * in `inbound()` and this test goes red.
	 *
	 * @return void
	 */
	public function testATamperedWsseTokenIsRefused(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(self::ENDPOINT);
		$this->inspector->method('verifyWsse')->willReturn(false);

		// The refusal must happen BEFORE the envelope is recorded. An endpoint
		// that logs first and refuses second has already accepted the message.
		$this->messageHandler->expects($this->never())->method('logInbound');

		$response = $this->controller(self::ENVELOPE)->inbound();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData());

	}//end testATamperedWsseTokenIsRefused()


	/**
	 * An envelope from an unconfigured sender is refused with 400.
	 *
	 * @return void
	 */
	public function testAnUnresolvableEndpointIsRefused(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(null);
		$this->inspector->expects($this->never())->method('verifyWsse');
		$this->messageHandler->expects($this->never())->method('logInbound');

		$response = $this->controller(self::ENVELOPE)->inbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('unknown endpoint', $response->getData());

	}//end testAnUnresolvableEndpointIsRefused()


	/**
	 * An empty body is refused with 400 before anything is resolved.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsRefused(): void {
		$this->inspector->expects($this->never())->method('resolveEndpoint');
		$this->messageHandler->expects($this->never())->method('logInbound');

		$response = $this->controller('')->inbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('empty body', $response->getData());

	}//end testAnEmptyBodyIsRefused()


	/**
	 * THE POSITIVE CONTROL — a verified sender is processed and acknowledged.
	 *
	 * Without this arm, a controller that answered 422 on its first line would
	 * satisfy every refusal test above. A guard that refuses everything is not
	 * a working guard; it is an outage.
	 *
	 * @return void
	 */
	public function testAVerifiedSenderIsProcessedAndAcknowledged(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(self::ENDPOINT);
		$this->inspector->method('verifyWsse')->willReturn(true);

		$this->messageHandler->expects($this->once())->method('logInbound');

		$response = $this->controller(self::ENVELOPE)->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ack', $response->getData());

	}//end testAVerifiedSenderIsProcessedAndAcknowledged()


	/**
	 * The guard is keyed to the RESOLVED endpoint, not to the envelope alone.
	 *
	 * A checker that verified the token against anything other than the sending
	 * endpoint's own stored credentials would let one configured system
	 * impersonate another. This pins the argument.
	 *
	 * @return void
	 */
	public function testTheTokenIsVerifiedAgainstTheResolvedEndpoint(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(self::ENDPOINT);
		$this->inspector->expects($this->once())
			->method('verifyWsse')
			->with(self::ENVELOPE, self::ENDPOINT)
			->willReturn(true);

		$this->controller(self::ENVELOPE)->inbound();

	}//end testTheTokenIsVerifiedAgainstTheResolvedEndpoint()


}//end class
