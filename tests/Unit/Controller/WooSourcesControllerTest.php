<?php

/**
 * Woo Sources Controller Test
 *
 * The three Gather documents endpoints answer with the right status and body
 * for a caller with and without access, with integriq absent and present.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WooSourcesController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\Dossiq\Woo\WooGatherAdd;
use OCA\Dossiq\Woo\WooSources;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\WooSourcesController
 */
class WooSourcesControllerTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	private bool $mayRead = true;

	private bool $mayChange = true;

	private bool $integriqInstalled = false;

	private bool $signedIn = true;

	private InMemoryEventDispatcher $dispatcher;

	private WooGatherAdd&MockObject $gatherAdd;

	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->gatherAdd = $this->createMock(WooGatherAdd::class);
	}//end setUp()

	/**
	 * The controller over a real WooSources and the current flags.
	 *
	 * @return WooSourcesController The controller.
	 */
	private function controller(): WooSourcesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn ? $user : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(fn (): bool => $this->mayRead);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(fn (): bool => $this->mayChange);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => ($app === 'integriq' && $this->integriqInstalled));
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => ($app === 'integriq' && $this->integriqInstalled));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$sources = new WooSources(
			appManager: $apps,
			dispatcher: $this->dispatcher,
			appConfig: $this->createMock(IAppConfig::class),
			settingsService: $this->createMock(SettingsService::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

		return new WooSourcesController(
			appName: 'dossiq',
			request: $request,
			sources: $sources,
			gatherAdd: $this->gatherAdd,
			accessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	public function testTheSourcesWithoutIntegriqListItAsNotConnected(): void {
		$response = $this->controller()->index(id: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$sources = array_column($response->getData()['sources'], null, 'id');
		self::assertFalse($sources['microsoft365']['available']);
		self::assertSame('integriq-not-installed', $sources['microsoft365']['reason']);
	}//end testTheSourcesWithoutIntegriqListItAsNotConnected()

	public function testTheSourcesWithIntegriqOfferIt(): void {
		$this->integriqInstalled = true;
		$response = $this->controller()->index(id: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue(array_column($response->getData()['sources'], null, 'id')['microsoft365']['available']);
	}//end testTheSourcesWithIntegriqOfferIt()

	public function testTheSourcesRefuseACallerWhoCannotReadTheCase(): void {
		$this->mayRead = false;
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index(id: self::CASE_ID)->getStatus());

		$this->signedIn = false;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index(id: self::CASE_ID)->getStatus());
	}//end testTheSourcesRefuseACallerWhoCannotReadTheCase()

	public function testTheSearchRefusesACallerWithoutCaseAccess(): void {
		$this->mayChange = false;
		$this->integriqInstalled = true;
		$this->params = ['source' => 'microsoft365', 'terms' => 'Stationsweg'];

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->search(id: self::CASE_ID)->getStatus());
		self::assertSame([], $this->dispatcher->dispatched, 'nothing reaches integriq for a refused caller');
	}//end testTheSearchRefusesACallerWithoutCaseAccess()

	public function testTheSearchMapsAnIntegriqAnswerToRows(): void {
		$this->integriqInstalled = true;
		$this->params = ['source' => 'microsoft365', 'terms' => 'Stationsweg', 'from' => '2025-01-01', 'to' => 'not a date'];
		$asked = null;
		$this->dispatcher->addListener(
			DocumentSearchRequestedEvent::class,
			static function (DocumentSearchRequestedEvent $event) use (&$asked): void {
				$asked = $event;
				$event->setResult(['hits' => [['remoteId' => 'message:1', 'title' => 'Re: Stationsweg', 'path' => 'Postvak P. Jansen', 'modifiedAt' => '2025-02-02', 'snippet' => 'de planning', 'entityType' => 'message']], 'moreCount' => 0, 'notices' => []]);
			}
		);

		$response = $this->controller()->search(id: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('microsoft365', $response->getData()['source']);
		self::assertSame('Re: Stationsweg', $response->getData()['rows'][0]['name']);
		self::assertSame(0, $response->getData()['remaining']);
		self::assertSame('2025-01-01', $asked->getFrom());
		self::assertNull($asked->getTo(), 'a value that is not a date is not passed on');
	}//end testTheSearchMapsAnIntegriqAnswerToRows()

	public function testTheSearchRefusesPlatformSourcesEmptyTermsAndAnAbsentIntegriq(): void {
		$this->params = ['source' => 'files', 'terms' => 'x'];
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->search(id: self::CASE_ID)->getStatus());

		$this->params = ['source' => 'microsoft365', 'terms' => '  '];
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->search(id: self::CASE_ID)->getStatus());

		$this->params = ['source' => 'microsoft365', 'terms' => 'x'];
		$response = $this->controller()->search(id: self::CASE_ID);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('integriq-not-installed', $response->getData()['error']);
	}//end testTheSearchRefusesPlatformSourcesEmptyTermsAndAnAbsentIntegriq()

	public function testTheAddAnswersEachPickAndRefusesWhenNoneWasAdded(): void {
		$this->params = ['picks' => json_encode([['source' => 'files', 'key' => '11'], ['source' => 'files', 'key' => '13']]), 'terms' => 'Stationsweg'];
		$this->gatherAdd->expects(self::exactly(2))->method('addPicks')->willReturnOnConsecutiveCalls(
			[['key' => '11', 'status' => 'added'], ['key' => '13', 'status' => 'refused']],
			[['key' => '13', 'status' => 'refused']],
		);

		$response = $this->controller()->add(id: self::CASE_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()['added']);
		self::assertSame(1, $response->getData()['refused']);

		$response = $this->controller()->add(id: self::CASE_ID);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testTheAddAnswersEachPickAndRefusesWhenNoneWasAdded()

	public function testTheAddRefusesNoPicksAndACallerWithoutCaseAccess(): void {
		$this->gatherAdd->expects(self::never())->method('addPicks');

		$this->params = ['picks' => []];
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->add(id: self::CASE_ID)->getStatus());

		$this->mayChange = false;
		$this->params = ['picks' => [['source' => 'files', 'key' => '11']]];
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->add(id: self::CASE_ID)->getStatus());

		$this->signedIn = false;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->add(id: self::CASE_ID)->getStatus());
	}//end testTheAddRefusesNoPicksAndACallerWithoutCaseAccess()
}//end class
