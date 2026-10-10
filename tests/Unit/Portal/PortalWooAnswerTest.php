<?php

/**
 * The requester's answer lands on their own open request, and nowhere else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Controller\PortalWooAnswerController;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Portal\PortalWooAnswer;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-001 through the controller portaliq forwards to, with the real services.
 *
 * @covers \OCA\Dossiq\Controller\PortalWooAnswerController
 * @covers \OCA\Dossiq\Portal\PortalWooAnswer
 *
 * @uses \OCA\Dossiq\Portal\PortalAssertionVerifier
 * @uses \OCA\Dossiq\Service\AanvullingsverzoekService
 * @uses \OCA\Dossiq\Service\AanvullingsverzoekResolutionService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class PortalWooAnswerTest extends TestCase {

	/**
	 * The resident the minted assertion names.
	 */
	private const ANNA = 'subject-ref-anna';

	/**
	 * Cases and requests.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The act that would resume the term.
	 *
	 * @var InformationRequestService&MockObject
	 */
	private InformationRequestService&MockObject $act;

	/**
	 * Tells the handler.
	 *
	 * @var ApplicantPortalActs&MockObject
	 */
	private ApplicantPortalActs&MockObject $acts;

	/**
	 * Seed one Woo case of Anna with an open clarification request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-87', row: ['identifier' => '2026-0087', 'portalSubject' => self::ANNA, 'assignee' => 'behandelaar']);
		$this->store->seed(schema: 'aanvullingsverzoek', uuid: 'req-1', row: [
			'case' => 'case-87',
			'portalSubject' => self::ANNA,
			'summary' => 'Over welke speeltuinen en welke jaren gaat uw verzoek?',
			'missingItems' => [],
			'requestedAt' => '2026-10-06T09:00:00+02:00',
			'hersteltermijn' => '2026-10-20',
			'state' => 'open',
		]);
		$this->act = $this->createMock(InformationRequestService::class);
		$this->acts = $this->createMock(ApplicantPortalActs::class);
	}//end setUp()

	/**
	 * Build the controller for one forward.
	 *
	 * @param array<string, mixed> $body      The forwarded body.
	 * @param string|null          $assertion The X-Portal-Subject header, minted for Anna when null.
	 *
	 * @return PortalWooAnswerController
	 */
	private function controller(array $body, ?string $assertion = null): PortalWooAnswerController {
		$assertion = ($assertion ?? PortalAssertionVerifierTest::mint());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === PortalAssertionVerifier::HEADER) ? $assertion : '');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		$requests = new AanvullingsverzoekService(act: $this->act, settingsService: $settings, logger: new NullLogger());
		$answers = new PortalWooAnswer(
			settings: $settings,
			requests: $requests,
			resolution: new AanvullingsverzoekResolutionService(requests: $requests, act: $this->act, logger: new NullLogger()),
			acts: $this->acts,
			logger: new NullLogger(),
		);

		return new PortalWooAnswerController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			answers: $answers,
			throttler: $this->createMock(IThrottler::class),
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * The answer is stored on the request, the request stays open, and the handler is told once.
	 *
	 * @return void
	 */
	public function testTheAnswerLandsOnTheOpenRequest(): void {
		$this->acts->expects($this->once())->method('recordWrite')
			->with('case-87', 'answer', ['applicantAnswer'], $this->isType('string'))
			->willReturn(true);

		$response = $this->controller(['requestId' => 'req-1', 'antwoord' => 'Het gaat om de speeltuinen in Oosthaven, 2023 tot nu'])->answer();

		$this->assertSame(200, $response->getStatus());
		$request = $this->store->row(schema: 'aanvullingsverzoek', uuid: 'req-1');
		$this->assertSame('Het gaat om de speeltuinen in Oosthaven, 2023 tot nu', $request['applicantAnswer']);
		$this->assertSame('open', $request['state']);
		$this->assertNotEmpty($request['applicantAnsweredAt']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'aanvullingsverzoek', payload: $request, creating: false));
	}//end testTheAnswerLandsOnTheOpenRequest()

	/**
	 * A request of another resident answers 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherSubjectsRequestIsRefused(): void {
		$this->acts->expects($this->never())->method('recordWrite');
		$before = $this->store->writes;

		$response = $this->controller(
			['requestId' => 'req-1', 'antwoord' => 'Mijn antwoord'],
			PortalAssertionVerifierTest::mint(['sub' => 'subject-ref-mallory'])
		)->answer();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame($before, $this->store->writes);
		$this->assertArrayNotHasKey('applicantAnswer', $this->store->row(schema: 'aanvullingsverzoek', uuid: 'req-1'));
	}//end testAnotherSubjectsRequestIsRefused()

	/**
	 * A request the handler closed answers 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testAClosedRequestIsRefused(): void {
		$this->store->seed(schema: 'aanvullingsverzoek', uuid: 'req-1', row: array_merge(
			$this->store->row(schema: 'aanvullingsverzoek', uuid: 'req-1'),
			['state' => 'answered']
		));
		$this->acts->expects($this->never())->method('recordWrite');
		$before = $this->store->writes;

		$response = $this->controller(['requestId' => 'req-1', 'antwoord' => 'Nog een antwoord'])->answer();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame($before, $this->store->writes);
	}//end testAClosedRequestIsRefused()

	/**
	 * The term stays paused: the act that resumes it is never called, and the case still waits.
	 *
	 * @return void
	 */
	public function testTheTermStaysPaused(): void {
		$this->act->expects($this->never())->method('receive');

		$response = $this->controller(['requestId' => 'req-1', 'antwoord' => 'Alle speeltuinen'])->answer();

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('answeredAt', $this->store->row(schema: 'aanvullingsverzoek', uuid: 'req-1'));
		$this->assertArrayNotHasKey('waitingOnApplicant', $this->store->row(schema: 'case', uuid: 'case-87'));
	}//end testTheTermStaysPaused()

	/**
	 * An empty or too long answer is refused with 400; a bad assertion with 401.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerOrABadAssertionIsRefused(): void {
		$this->assertSame(400, $this->controller(['requestId' => 'req-1', 'antwoord' => '   '])->answer()->getStatus());
		$this->assertSame(400, $this->controller(['requestId' => 'req-1', 'antwoord' => str_repeat('a', 4001)])->answer()->getStatus());
		$this->assertSame(401, $this->controller(['requestId' => 'req-1', 'antwoord' => 'x'], 'not-a-jwt')->answer()->getStatus());
		$this->assertSame(
			403,
			$this->controller(['requestId' => 'req-1', 'antwoord' => 'x'], PortalAssertionVerifierTest::mint(['audience' => 'employee']))->answer()->getStatus()
		);
	}//end testAnEmptyAnswerOrABadAssertionIsRefused()
}//end class
