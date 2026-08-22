<?php

/**
 * SubsidieController Wire-Contract Tests
 *
 * Contract coverage for the five subsidy-lifecycle endpoints that had no
 * automated proof of their wire behaviour (gate-25): `createBeschikking`,
 * `publishBeschikking`, `signBeschikking`, `approveTussenrapportage` and
 * `finalizeVaststelling`.
 *
 * Each one carries legal effect under AWB titel 4.2 — publishing a beschikking
 * starts the bezwaartermijn, finalising a vaststelling can trigger a
 * terugvordering — so the properties pinned here are:
 *
 *  - every endpoint answers 401 with the app's own `Authenticatie vereist`
 *    body for a caller without a session, and its service is never reached;
 *  - `createBeschikking` strips the ROUTING parameters out of the payload it
 *    persists. `bodyParams()` unsets `id`/`decisionId`/`reportId`/
 *    `uitvoeringId`/`vaststellingId`/`_route`; if that ever regressed, the
 *    router's own `_route` string would be written into the beschikking;
 *  - `approveTussenrapportage` forwards an ABSENT optional as null rather than
 *    as `''`/`0.0` — an approved amount of zero is a real (and very different)
 *    assessment from "no amount was set";
 *  - `finalizeVaststelling` forwards its three money arguments as floats in the
 *    declared order granted/actual/advances. All three are same-typed, and
 *    transposing them flips the over/under-payment computation, i.e. it decides
 *    whether the citizen is billed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SubsidieController;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCA\Dossiq\Service\Subsidie\VaststellingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for SubsidieController.
 *
 * @covers \OCA\Dossiq\Controller\SubsidieController
 */
class SubsidieControllerContractTest extends TestCase {

	/**
	 * The inbound request mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The grant-decision service mock.
	 *
	 * @var BeschikkingService|MockObject
	 */
	private BeschikkingService $decisionService;

	/**
	 * The interim-report service mock.
	 *
	 * @var TussenrapportageService|MockObject
	 */
	private TussenrapportageService $tussenrapportage;

	/**
	 * The settlement service mock.
	 *
	 * @var VaststellingService|MockObject
	 */
	private VaststellingService $determinationService;

	/**
	 * The session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var SubsidieController
	 */
	private SubsidieController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->decisionService = $this->createMock(BeschikkingService::class);
		$this->tussenrapportage = $this->createMock(TussenrapportageService::class);
		$this->determinationService = $this->createMock(VaststellingService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new SubsidieController(
			request: $this->request,
			subsidyService: $this->createMock(SubsidieService::class),
			decisionService: $this->decisionService,
			tussenrapportage: $this->tussenrapportage,
			determinationService: $this->determinationService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Mark the session as authenticated for caseworker `alice`.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Feed the single-parameter accessor.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
	}//end withParams()

	/**
	 * Feed the whole parameter bag (used by `bodyParams()`).
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function withParamBag(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}//end withParamBag()

	/**
	 * Every one of the five endpoints refuses an anonymous caller with 401 and
	 * the app's own Dutch error body, and no lifecycle service is reached.
	 *
	 * Each of these five writes a legally effective record, so a body that ran
	 * before the session check would be a write by an unauthenticated caller.
	 *
	 * @return void
	 */
	public function testAllFiveLifecycleEndpointsRefuseAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams([]);
		$this->withParamBag([]);

		$this->decisionService->expects($this->never())->method('createDraft');
		$this->decisionService->expects($this->never())->method('publish');
		$this->decisionService->expects($this->never())->method('sign');
		$this->tussenrapportage->expects($this->never())->method('approveReport');
		$this->determinationService->expects($this->never())->method('finalize');

		$responses = [
			'createBeschikking' => $this->controller->createBeschikking(id: 'aanvraag-1'),
			'publishBeschikking' => $this->controller->publishBeschikking(decisionId: 'besch-1'),
			'signBeschikking' => $this->controller->signBeschikking(decisionId: 'besch-1'),
			'approveTussenrapportage' => $this->controller->approveTussenrapportage(reportId: 'rap-1'),
			'finalizeVaststelling' => $this->controller->finalizeVaststelling(determinationId: 'vast-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must answer 401 for a caller without a session'
			);
			$this->assertSame(
				['error' => 'Authenticatie vereist'],
				$response->getData(),
				$endpoint . ' must answer the app-standard unauthenticated body'
			);
		}
	}//end testAllFiveLifecycleEndpointsRefuseAnAnonymousCaller()

	/**
	 * createBeschikking drafts against the aanvraag named in the route, with
	 * the routing parameters stripped out of the persisted payload and the
	 * sequence lifted out of the body into its own argument.
	 *
	 * @return void
	 */
	public function testCreateBeschikkingStripsRoutingParametersFromThePayload(): void {
		$this->authenticate();
		$this->withParamBag(
			[
				'id' => 'aanvraag-1',
				'_route' => 'dossiq.subsidie.createBeschikking',
				'decisionId' => 'leaked-decision',
				'reportId' => 'leaked-report',
				'uitvoeringId' => 'leaked-uitvoering',
				'vaststellingId' => 'leaked-vaststelling',
				'sequence' => 3,
				'grantedAmount' => 25000,
				'motivation' => 'Voldoet aan de regeling.',
			]
		);

		$draft = ['id' => 'besch-1', 'status' => 'concept'];

		$this->decisionService->expects($this->once())
			->method('createDraft')
			->with(
				'aanvraag-1',
				['grantedAmount' => 25000, 'motivation' => 'Voldoet aan de regeling.'],
				3
			)
			->willReturn($draft);

		$response = $this->controller->createBeschikking(id: 'aanvraag-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($draft, $response->getData());
	}//end testCreateBeschikkingStripsRoutingParametersFromThePayload()

	/**
	 * A beschikking with no explicit sequence is the FIRST one — the default is
	 * 1, not 0, because the sequence numbers the decisions on an aanvraag.
	 *
	 * @return void
	 */
	public function testCreateBeschikkingDefaultsTheSequenceToOne(): void {
		$this->authenticate();
		$this->withParamBag(['id' => 'aanvraag-1', 'motivation' => 'Akkoord.']);

		$this->decisionService->expects($this->once())
			->method('createDraft')
			->with('aanvraag-1', ['motivation' => 'Akkoord.'], 1)
			->willReturn(['id' => 'besch-1']);

		$response = $this->controller->createBeschikking(id: 'aanvraag-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateBeschikkingDefaultsTheSequenceToOne()

	/**
	 * A service-level validation refusal on createBeschikking is a 400 carrying
	 * the reason, not a 201 with nothing behind it.
	 *
	 * @return void
	 */
	public function testCreateBeschikkingMapsAValidationRefusalTo400(): void {
		$this->authenticate();
		$this->withParamBag(['id' => 'aanvraag-1']);

		$this->decisionService->method('createDraft')
			->willThrowException(new OCSBadRequestException('Aanvraag not found'));

		$response = $this->controller->createBeschikking(id: 'aanvraag-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Aanvraag not found'], $response->getData());
	}//end testCreateBeschikkingMapsAValidationRefusalTo400()

	/**
	 * publishBeschikking publishes the beschikking named in the route and
	 * answers 200 with the published record — publication is what starts the
	 * bezwaartermijn, so it must be the route id that is published and nothing
	 * else.
	 *
	 * @return void
	 */
	public function testPublishBeschikkingPublishesTheRoutedDecision(): void {
		$this->authenticate();
		$this->withParams(['decisionId' => 'besch-other']);

		$published = ['id' => 'besch-1', 'status' => 'published', 'bezwaarDeadline' => '2026-09-27'];

		$this->decisionService->expects($this->once())
			->method('publish')
			->with('besch-1')
			->willReturn($published);

		$response = $this->controller->publishBeschikking(decisionId: 'besch-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($published, $response->getData());
	}//end testPublishBeschikkingPublishesTheRoutedDecision()

	/**
	 * An unpublishable beschikking (e.g. still unsigned) is a 400 carrying the
	 * reason — publication must not report success it did not perform.
	 *
	 * @return void
	 */
	public function testPublishBeschikkingMapsARefusalTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->decisionService->method('publish')
			->willThrowException(new OCSBadRequestException('Beschikking must be signed before publication'));

		$response = $this->controller->publishBeschikking(decisionId: 'besch-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Beschikking must be signed before publication'],
			$response->getData()
		);
	}//end testPublishBeschikkingMapsARefusalTo400()

	/**
	 * signBeschikking passes ONLY the routed decision id — the signer is
	 * derived inside the service from the session and is deliberately not a
	 * controller argument, so no caller-supplied signer can reach it.
	 *
	 * @return void
	 */
	public function testSignBeschikkingPassesOnlyTheRoutedDecisionId(): void {
		$this->authenticate();
		$this->withParams(['signer' => 'mallory', 'decisionId' => 'besch-other']);

		$signed = ['id' => 'besch-1', 'status' => 'signed'];

		$this->decisionService->expects($this->once())
			->method('sign')
			->with('besch-1')
			->willReturn($signed);

		$response = $this->controller->signBeschikking(decisionId: 'besch-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($signed, $response->getData());
	}//end testSignBeschikkingPassesOnlyTheRoutedDecisionId()

	/**
	 * A signing refusal is a 400 carrying the reason.
	 *
	 * @return void
	 */
	public function testSignBeschikkingMapsARefusalTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->decisionService->method('sign')
			->willThrowException(new OCSBadRequestException('Beschikking already signed'));

		$response = $this->controller->signBeschikking(decisionId: 'besch-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Beschikking already signed'], $response->getData());
	}//end testSignBeschikkingMapsARefusalTo400()

	/**
	 * approveTussenrapportage forwards the assessment opinion as a string and
	 * the approved amount as a FLOAT — a numeric string reaching the service
	 * would be persisted as a string amount.
	 *
	 * @return void
	 */
	public function testApproveTussenrapportageForwardsTheOpinionAndAFloatAmount(): void {
		$this->authenticate();
		$this->withParams(['beoordelingsoordeel' => 'positief', 'approvedAmount' => '1500.50']);

		$approved = ['id' => 'rap-1', 'status' => 'approved'];

		$this->tussenrapportage->expects($this->once())
			->method('approveReport')
			->with('rap-1', 'positief', 1500.50)
			->willReturn($approved);

		$response = $this->controller->approveTussenrapportage(reportId: 'rap-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($approved, $response->getData());
	}//end testApproveTussenrapportageForwardsTheOpinionAndAFloatAmount()

	/**
	 * An omitted opinion or amount reaches the service as NULL, not as `''` or
	 * `0.0`. Approving a report "for 0 euro" is a real assessment and must not
	 * be indistinguishable from approving it without setting an amount.
	 *
	 * @return void
	 */
	public function testApproveTussenrapportageForwardsAbsentOptionalsAsNullNotZero(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->tussenrapportage->expects($this->once())
			->method('approveReport')
			->with('rap-1', null, null)
			->willReturn(['id' => 'rap-1']);

		$response = $this->controller->approveTussenrapportage(reportId: 'rap-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testApproveTussenrapportageForwardsAbsentOptionalsAsNullNotZero()

	/**
	 * An approval refusal is a 400 carrying the reason.
	 *
	 * @return void
	 */
	public function testApproveTussenrapportageMapsARefusalTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->tussenrapportage->method('approveReport')
			->willThrowException(new OCSBadRequestException('Tussenrapportage not found'));

		$response = $this->controller->approveTussenrapportage(reportId: 'rap-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Tussenrapportage not found'], $response->getData());
	}//end testApproveTussenrapportageMapsARefusalTo400()

	/**
	 * finalizeVaststelling forwards granted amount, actual cost and total
	 * advances as floats IN THAT ORDER. The service subtracts advances from the
	 * determined amount to decide whether a terugvordering is triggered, so a
	 * transposition here decides whether the citizen gets a bill.
	 *
	 * @return void
	 */
	public function testFinalizeVaststellingForwardsTheThreeAmountsInDeclaredOrder(): void {
		$this->authenticate();
		$this->withParams(
			[
				'grantedAmount' => '10000',
				'werkelijkeKosten' => '8000.25',
				'totaalVoorschotten' => '9000',
			]
		);

		$result = ['id' => 'vast-1', 'determinedAmount' => 8000.25, 'recoveryTrigger' => true];

		$this->determinationService->expects($this->once())
			->method('finalize')
			->with('vast-1', 10000.0, 8000.25, 9000.0)
			->willReturn($result);

		$response = $this->controller->finalizeVaststelling(determinationId: 'vast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}//end testFinalizeVaststellingForwardsTheThreeAmountsInDeclaredOrder()

	/**
	 * Omitted amounts default to 0.0 floats rather than being dropped — the
	 * service takes three non-nullable floats, so a dropped argument would be a
	 * TypeError rather than a settlement.
	 *
	 * @return void
	 */
	public function testFinalizeVaststellingDefaultsAbsentAmountsToZeroFloats(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->determinationService->expects($this->once())
			->method('finalize')
			->with('vast-1', 0.0, 0.0, 0.0)
			->willReturn(['id' => 'vast-1']);

		$response = $this->controller->finalizeVaststelling(determinationId: 'vast-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testFinalizeVaststellingDefaultsAbsentAmountsToZeroFloats()

	/**
	 * A settlement refusal is a 400 carrying the reason.
	 *
	 * @return void
	 */
	public function testFinalizeVaststellingMapsARefusalTo400(): void {
		$this->authenticate();
		$this->withParams([]);

		$this->determinationService->method('finalize')
			->willThrowException(new OCSBadRequestException('Vaststelling already finalised'));

		$response = $this->controller->finalizeVaststelling(determinationId: 'vast-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Vaststelling already finalised'], $response->getData());
	}//end testFinalizeVaststellingMapsARefusalTo400()
}//end class
