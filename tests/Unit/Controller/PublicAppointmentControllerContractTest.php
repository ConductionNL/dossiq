<?php

/**
 * PublicAppointmentController Wire-Contract Tests
 *
 * Contract coverage for the two citizen-facing token routes (gate-25):
 * `GET /api/public/appointment/{token}` and
 * `POST /api/public/appointment/{token}/cancel`. Both are `@PublicPage` +
 * `@NoCSRFRequired`: no Nextcloud session, no CSRF token, no middleware
 * refusal. The token in the emailed link is the entire access-control system,
 * and everything the controller emits goes to an unauthenticated stranger.
 * These tests pin:
 *
 *  - an unknown token is a 404 on BOTH endpoints, and on `cancel` it is a 404
 *    with NO cancellation attempted — a token that does not resolve must not
 *    reach the mutation;
 *  - `view` emits a FIVE-KEY PROJECTION and nothing else. The stored record
 *    carries the citizen's name, BSN-bearing fields and the token itself; the
 *    realistic defect on a public endpoint is returning `$appointment`
 *    wholesale, which renders identically in the UI while leaking. The test
 *    asserts the exact key set, so an added key fails;
 *  - `cancel` refuses an already-cancelled appointment with 400 and does not
 *    re-issue the cancellation (double-cancel is not idempotent downstream);
 *  - `cancel` addresses the appointment by the record's OWN uuid, never by the
 *    caller-supplied token.
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

use OCA\Dossiq\Controller\PublicAppointmentController;
use OCA\Dossiq\Service\AppointmentService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for PublicAppointmentController.
 *
 * @covers \OCA\Dossiq\Controller\PublicAppointmentController
 */
class PublicAppointmentControllerContractTest extends TestCase {

	/**
	 * A stored appointment carrying more than the endpoint may disclose.
	 *
	 * @var array<string,mixed>
	 */
	private const STORED = [
		'id' => 17,
		'uuid' => 'afspraak-uuid-17',
		'publicToken' => 'tok-secret',
		'dateTime' => '2026-09-01T10:30:00+02:00',
		'duration' => 45,
		'status' => 'scheduled',
		'locationId' => 'loc-stadhuis',
		'productId' => 'prod-paspoort',
		'citizenName' => 'J. de Vries',
		'citizenBsn' => '123456782',
		'internalNotes' => 'balie 4, tolk aanwezig',
	];

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The appointment service.
	 *
	 * @var AppointmentService|MockObject
	 */
	private AppointmentService $appointmentService;

	/**
	 * The controller under test.
	 *
	 * @var PublicAppointmentController
	 */
	private PublicAppointmentController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appointmentService = $this->createMock(AppointmentService::class);

		$this->controller = new PublicAppointmentController(
			request: $this->request,
			appointmentService: $this->appointmentService,
		);
	}//end setUp()

	/**
	 * An unresolvable token is a 404 on the read endpoint.
	 *
	 * @return void
	 */
	public function testViewReturns404ForATokenThatResolvesToNothing(): void {
		$this->appointmentService->expects($this->once())
			->method('getAppointmentByToken')
			->with('tok-bogus')
			->willReturn(null);

		$response = $this->controller->view(token: 'tok-bogus');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Afspraak niet gevonden'], $response->getData());
	}//end testViewReturns404ForATokenThatResolvesToNothing()

	/**
	 * The public view discloses exactly five appointment fields — not the
	 * stored record, and never the token or the citizen's identity.
	 *
	 * @return void
	 */
	public function testViewDisclosesOnlyTheFiveWhitelistedFieldsToAnAnonymousCaller(): void {
		$this->appointmentService->method('getAppointmentByToken')->willReturn(self::STORED);

		$response = $this->controller->view(token: 'tok-secret');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['dateTime', 'duration', 'status', 'locationId', 'productId'],
			array_keys($data['appointment']),
			'the public projection must not grow: every extra key ships to an anonymous caller'
		);
		$this->assertSame(
			[
				'success' => true,
				'appointment' => [
					'dateTime' => '2026-09-01T10:30:00+02:00',
					'duration' => 45,
					'status' => 'scheduled',
					'locationId' => 'loc-stadhuis',
					'productId' => 'prod-paspoort',
				],
			],
			$data
		);
	}//end testViewDisclosesOnlyTheFiveWhitelistedFieldsToAnAnonymousCaller()

	/**
	 * A record missing the optional fields still yields the documented
	 * defaults instead of nulls the citizen-facing page cannot render.
	 *
	 * @return void
	 */
	public function testViewSubstitutesTheDocumentedDefaultsForAbsentFields(): void {
		$this->appointmentService->method('getAppointmentByToken')
			->willReturn(['uuid' => 'afspraak-1']);

		$data = $this->controller->view(token: 'tok-1')->getData();

		$this->assertSame(30, $data['appointment']['duration']);
		$this->assertSame('scheduled', $data['appointment']['status']);
		$this->assertNull($data['appointment']['dateTime']);
	}//end testViewSubstitutesTheDocumentedDefaultsForAbsentFields()

	/**
	 * An unresolvable token is a 404 on cancel, and nothing is cancelled.
	 *
	 * @return void
	 */
	public function testCancelReturns404AndCancelsNothingForAnUnknownToken(): void {
		$this->appointmentService->method('getAppointmentByToken')->willReturn(null);
		$this->appointmentService->expects($this->never())->method('cancelAppointment');

		$response = $this->controller->cancel(token: 'tok-bogus');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Afspraak niet gevonden'], $response->getData());
	}//end testCancelReturns404AndCancelsNothingForAnUnknownToken()

	/**
	 * An already-cancelled appointment is a 400 and is not cancelled twice.
	 *
	 * @return void
	 */
	public function testCancelRefusesAnAlreadyCancelledAppointmentWithoutReCancelling(): void {
		$this->appointmentService->method('getAppointmentByToken')
			->willReturn(['uuid' => 'afspraak-1', 'status' => 'cancelled']);
		$this->appointmentService->expects($this->never())->method('cancelAppointment');

		$response = $this->controller->cancel(token: 'tok-secret');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Afspraak is al geannuleerd'], $response->getData());
	}//end testCancelRefusesAnAlreadyCancelledAppointmentWithoutReCancelling()

	/**
	 * The cancellation addresses the record's own uuid, not the token the
	 * anonymous caller supplied.
	 *
	 * @return void
	 */
	public function testCancelActsOnTheRecordUuidRatherThanTheSuppliedToken(): void {
		$this->appointmentService->method('getAppointmentByToken')->willReturn(self::STORED);

		$cancelled = ['uuid' => 'afspraak-uuid-17', 'status' => 'cancelled'];

		$this->appointmentService->expects($this->once())
			->method('cancelAppointment')
			->with('afspraak-uuid-17')
			->willReturn($cancelled);

		$response = $this->controller->cancel(token: 'tok-secret');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'appointment' => $cancelled], $response->getData());
	}//end testCancelActsOnTheRecordUuidRatherThanTheSuppliedToken()
}//end class
