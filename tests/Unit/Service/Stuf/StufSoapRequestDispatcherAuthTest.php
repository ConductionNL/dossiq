<?php

/**
 * Unit tests for the inbound StUF sender authentication.
 *
 * These four cases exist because `StufController::cases()` and `::persons()`
 * are `#[PublicPage]` routes: nothing in Nextcloud's middleware stack will ever
 * refuse a caller on them, so the refusal has to come from the dispatcher and
 * has to be tested here.
 *
 * The suite is written so that DELETING the guard makes it red: two arms assert
 * a refusal that only exists because the guard runs (unknown sender, bad WSSE),
 * and one asserts that a verified sender still reaches the responder — so a
 * guard that refused everything would fail too.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufResponseBuilder;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCA\Dossiq\Service\Stuf\StufZknMessageResponder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that an inbound StUF envelope is authenticated before it is
 * interpreted.
 *
 * @covers \OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher
 */
class StufSoapRequestDispatcherAuthTest extends TestCase {
	/**
	 * A minimal, well-formed StUF envelope carrying a zender and a WSSE token.
	 */
	private const ENVELOPE = '<?xml version="1.0"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
		. ' xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
		. ' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310">'
		. '<soap:Body><zkn:zakLv01><stuf:stuurgegevens>'
		. '<stuf:zender><stuf:applicatie>ZAAKSYSTEEM</stuf:applicatie></stuf:zender>'
		. '</stuf:stuurgegevens></zkn:zakLv01></soap:Body></soap:Envelope>';

	/**
	 * @var StufResponseBuilder|MockObject
	 */
	private $responses;

	/**
	 * @var StufZknMessageResponder|MockObject
	 */
	private $responder;

	/**
	 * @var StufEnvelopeInspector|MockObject
	 */
	private $inspector;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->responses = $this->createMock(StufResponseBuilder::class);
		$this->responder = $this->createMock(StufZknMessageResponder::class);
		$this->inspector = $this->createMock(StufEnvelopeInspector::class);

		$this->responses->method('buildSoapFault')->willReturn('<fault/>');
		$this->responder->method('soapResponse')->willReturnCallback(
			static function (string $xml, int $statusCode = Http::STATUS_OK): DataDisplayResponse {
				return new DataDisplayResponse($xml, $statusCode);
			}
		);
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return StufSoapRequestDispatcher
	 */
	private function dispatcher(): StufSoapRequestDispatcher {
		return new StufSoapRequestDispatcher(
			$this->responses,
			$this->responder,
			$this->inspector,
			$this->createMock(LoggerInterface::class)
		);
	}//end dispatcher()

	/**
	 * An envelope whose zender matches no configured endpoint is refused, and
	 * the responder is never reached.
	 *
	 * @return void
	 */
	public function testUnknownSenderIsRefusedWithoutReachingTheResponder(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(null);
		$this->responder->expects($this->never())->method('respond');

		$response = $this->dispatcher()->dispatch(self::ENVELOPE, StufSoapRequestDispatcher::SERVICE_CASES);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testUnknownSenderIsRefusedWithoutReachingTheResponder()

	/**
	 * A known endpoint whose WSSE token does not verify is refused, and the
	 * responder is never reached.
	 *
	 * @return void
	 */
	public function testWsseMismatchIsRefusedWithoutReachingTheResponder(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(['id' => 'ep-1']);
		$this->inspector->method('verifyWsse')->willReturn(false);
		$this->responder->expects($this->never())->method('respond');

		$response = $this->dispatcher()->dispatch(self::ENVELOPE, StufSoapRequestDispatcher::SERVICE_PERSONS);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testWsseMismatchIsRefusedWithoutReachingTheResponder()

	/**
	 * The refusal must not distinguish an unknown sender from a bad password —
	 * otherwise the public route is an oracle for which zaaksystemen exist.
	 *
	 * ⚠️ The two `assertSame` comparisons below are NOT sufficient on their own.
	 * Deleting the guard makes both arms return the SAME non-refusal, so the
	 * equality holds trivially and this case stayed green while the two cases
	 * above went red — measured, by removing the guard and re-running. The
	 * `STATUS_UNAUTHORIZED` assertion is what makes this arm able to fail.
	 *
	 * @return void
	 */
	public function testBothRefusalsAreIndistinguishableToTheCaller(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(null);
		$unknown = $this->dispatcher()->dispatch(self::ENVELOPE, StufSoapRequestDispatcher::SERVICE_CASES);

		$this->setUp();
		$this->inspector->method('resolveEndpoint')->willReturn(['id' => 'ep-1']);
		$this->inspector->method('verifyWsse')->willReturn(false);
		$badPassword = $this->dispatcher()->dispatch(self::ENVELOPE, StufSoapRequestDispatcher::SERVICE_CASES);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $unknown->getStatus());
		$this->assertSame($unknown->getStatus(), $badPassword->getStatus());
		$this->assertSame($unknown->render(), $badPassword->render());
	}//end testBothRefusalsAreIndistinguishableToTheCaller()

	/**
	 * A verified sender is admitted — the guard denies, it does not close the
	 * route. Without this arm a guard that refused unconditionally would pass
	 * the two arms above.
	 *
	 * @return void
	 */
	public function testVerifiedSenderReachesTheResponder(): void {
		$this->inspector->method('resolveEndpoint')->willReturn(['id' => 'ep-1']);
		$this->inspector->method('verifyWsse')->willReturn(true);
		$this->responder->expects($this->once())
			->method('respond')
			->willReturn(new DataDisplayResponse('<ok/>', Http::STATUS_OK));

		$response = $this->dispatcher()->dispatch(self::ENVELOPE, StufSoapRequestDispatcher::SERVICE_CASES);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testVerifiedSenderReachesTheResponder()
}//end class
