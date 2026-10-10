<?php

/**
 * The portal route that starts a Woo request answers each case with its own status.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalWooRequestController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Tests\Unit\Portal\PortalAssertionVerifierTest;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives the controller with a real verifier and a mocked intake.
 *
 * @covers \OCA\Dossiq\Controller\PortalWooRequestController
 * @uses   \OCA\Dossiq\Portal\PortalAssertionVerifier
 * @uses   \OCA\Dossiq\Woo\WooRequestRefused
 */
class PortalWooRequestControllerTest extends TestCase {

	/**
	 * The intake the controller calls.
	 *
	 * @var WooRequestIntake&MockObject
	 */
	private WooRequestIntake&MockObject $intake;

	/**
	 * Build the controller for one request.
	 *
	 * @param string               $assertion The X-Portal-Subject header.
	 * @param array<string, mixed> $body      The forwarded body.
	 *
	 * @return PortalWooRequestController
	 */
	private function controller(string $assertion, array $body): PortalWooRequestController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === PortalAssertionVerifier::HEADER) ? $assertion : '');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$this->intake = $this->createMock(WooRequestIntake::class);

		return new PortalWooRequestController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			intake: $this->intake,
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A valid forward opens the case for the asserted resident, whatever the body says.
	 *
	 * @return void
	 */
	public function testAValidForwardOpensTheCaseForTheAssertedResident(): void {
		$controller = $this->controller(
			PortalAssertionVerifierTest::mint(),
			[
				'collectionId' => 'c-1',
				'onderwerp' => 'Parkeerbeleid',
				'omschrijving' => 'Alle stukken',
				'periodeVan' => '2025-01-01',
				'periodeTot' => '2025-12-31',
				'subjectRef' => 'subject-ref-mallory',
				'origin' => 'pipelinq',
			]
		);
		$this->intake->expects(self::once())->method('start')->with(
			[
				'subjectRef' => 'subject-ref-anna',
				'collectionId' => 'c-1',
				'onderwerp' => 'Parkeerbeleid',
				'omschrijving' => 'Alle stukken',
				'periodeVan' => '2025-01-01',
				'periodeTot' => '2025-12-31',
				'documentSoorten' => null,
				'toelichting' => null,
				'verzoekerNaam' => null,
				'verzoekerEmail' => null,
				'verzoekerType' => null,
				'origin' => 'portal',
				'originReference' => null,
			]
		)->willReturn(['caseId' => 'case-1', 'caseUrl' => 'https://x/case-1']);

		$response = $controller->start();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['caseId' => 'case-1', 'caseUrl' => 'https://x/case-1'], $response->getData());
	}//end testAValidForwardOpensTheCaseForTheAssertedResident()

	/**
	 * 🔴 The answers of steps 2 and 3 reach the intake, and the case number
	 * and date it answers reach the browser.
	 *
	 * A field that is not on the controller's own whitelist is dropped
	 * whatever the form sends, so a step could ask a question that never
	 * arrives, and nothing would say so (site-woo-request-in-steps REQ-SWS-010).
	 *
	 * @return void
	 */
	public function testTheNewAnswersReachTheIntakeAndTheCaseNumberComesBack(): void {
		$controller = $this->controller(
			PortalAssertionVerifierTest::mint(),
			[
				'onderwerp' => 'Parkeerbeleid',
				'documentSoorten' => ['besluiten', 'correspondentie'],
				'toelichting' => 'Het gaat om de Lindelaan.',
				'verzoekerNaam' => 'Sanne de Vries',
				'verzoekerEmail' => 'sanne@example.org',
				'verzoekerType' => 'journalist',
			]
		);
		$this->intake->expects(self::once())->method('start')->with(
			self::callback(
				static function (array $request): bool {
					return $request['documentSoorten'] === ['besluiten', 'correspondentie']
						&& $request['toelichting'] === 'Het gaat om de Lindelaan.'
						&& $request['verzoekerNaam'] === 'Sanne de Vries'
						&& $request['verzoekerEmail'] === 'sanne@example.org'
						&& $request['verzoekerType'] === 'journalist';
				}
			)
		)->willReturn(
			[
				'caseId' => 'case-1',
				'caseUrl' => 'https://x/case-1',
				'identifier' => '2026-0003',
				'deadline' => '2026-10-30',
			]
		);

		$response = $controller->start();

		self::assertSame(201, $response->getStatus());
		self::assertSame('2026-0003', $response->getData()['identifier']);
		self::assertSame('2026-10-30', $response->getData()['deadline']);
	}//end testTheNewAnswersReachTheIntakeAndTheCaseNumberComesBack()

	/**
	 * No assertion, no request.
	 *
	 * @return void
	 */
	public function testWithoutAValidAssertionTheAnswerIs401(): void {
		$controller = $this->controller('', ['onderwerp' => 'x']);
		$this->intake->expects(self::never())->method('start');

		self::assertSame(401, $controller->start()->getStatus());
	}//end testWithoutAValidAssertionTheAnswerIs401()

	/**
	 * An audience dossiq does not serve this action to is refused.
	 *
	 * @return void
	 */
	public function testASupplierIsRefused(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(['audience' => 'supplier']), ['onderwerp' => 'x']);
		$this->intake->expects(self::never())->method('start');

		self::assertSame(403, $controller->start()->getStatus());
	}//end testASupplierIsRefused()

	/**
	 * A citizen audience is served as well as client.
	 *
	 * @return void
	 */
	public function testACitizenIsServed(): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(['audience' => 'citizen']), ['onderwerp' => 'x']);
		$this->intake->method('start')->willReturn(['caseId' => 'c', 'caseUrl' => 'u']);

		self::assertSame(201, $controller->start()->getStatus());
	}//end testACitizenIsServed()

	/**
	 * Each refusal maps to its status.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function refusals(): array {
		return [
			'unusable' => [WooRequestRefused::INVALID, 400],
			'not the resident\'s dossier' => [WooRequestRefused::NOT_FOUND, 404],
			'no register or type' => [WooRequestRefused::UNAVAILABLE, 503],
		];
	}//end refusals()

	/**
	 * A refusal from the intake answers its status and its reason.
	 *
	 * @param string $reason The refusal.
	 * @param int    $status The expected status.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatus(string $reason, int $status): void {
		$controller = $this->controller(PortalAssertionVerifierTest::mint(), ['onderwerp' => 'x']);
		$this->intake->method('start')->willThrowException(new WooRequestRefused($reason, 'detail'));

		$response = $controller->start();

		self::assertSame($status, $response->getStatus());
		self::assertSame($reason, $response->getData()['error']);
	}//end testARefusalAnswersItsStatus()
}//end class
