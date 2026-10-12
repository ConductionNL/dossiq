<?php

/**
 * Unit tests for BrcDocumentLinkHandler.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\BrcCrossRegisterSync;
use OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The besluitinformatieobject routes, with the cross-register sync as a double.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrcDocumentLinkHandler
 * @uses \OCA\Dossiq\Support\NormalisesObjectRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class BrcDocumentLinkHandlerTest extends TestCase {
	private const BIO = '33333333-3333-4333-8333-333333333333';

	private const BASE = 'https://nc.example.com/besluiten/v1/';

	/**
	 * The store, or null for an instance without OpenRegister.
	 *
	 * @var RecordsBrcStoreCalls|null
	 */
	private ?RecordsBrcStoreCalls $store;

	/**
	 * The mapping of besluitinformatieobjecten, or null when it is missing.
	 *
	 * @var array|null
	 */
	private ?array $mapping = ['sourceRegister' => '7', 'sourceSchema' => '71'];

	/**
	 * What the rules answer; null answers valid with the body sent.
	 *
	 * @var array|null
	 */
	private ?array $ruleResult = null;

	/**
	 * The request body.
	 *
	 * @var array
	 */
	private array $body = [];

	/**
	 * Every call on the sync, as [method, arguments].
	 *
	 * @var array<int, array>
	 */
	private array $synced = [];

	/**
	 * Every notification published.
	 *
	 * @var array<int, array>
	 */
	private array $notifications = [];

	/**
	 * Every error logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Set up the store and the request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store   = new RecordsBrcStoreCalls();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['besluit' => 'x']);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return BrcDocumentLinkHandler
	 */
	private function handler(): BrcDocumentLinkHandler {
		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturnCallback(
			fn (...$arguments): array => ($this->ruleResult ?? ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $arguments[3]])
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturnCallback(fn (): ?object => $this->store);
		$zgw->method('getBusinessRulesService')->willReturn($rules);
		$zgw->method('getLogger')->willReturn($logger);
		$zgw->method('loadMappingConfig')->willReturnCallback(fn (): ?array => $this->mapping);
		$zgw->method('unavailableResponse')->willReturn(new JSONResponse(data: [], statusCode: 503));
		$zgw->method('mappingNotFoundResponse')->willReturn(new JSONResponse(data: [], statusCode: 404));
		$zgw->method('translateQueryParams')->willReturnCallback(static fn (array $params): array => ['decision' => $params['besluit']]);
		$zgw->method('buildBaseUrl')->willReturnCallback(
			static fn (IRequest $request, string $zgwApi, string $resource): string => 'https://nc.example.com/' . $zgwApi . '/v1/' . $resource
		);
		$zgw->method('createOutboundMapping')->willReturn(new \stdClass());
		$zgw->method('createInboundMapping')->willReturn(new \stdClass());
		$zgw->method('applyOutboundMapping')->willReturnCallback(static fn (array $objectData): array => ['out' => $objectData]);
		$zgw->method('applyInboundMapping')->willReturnCallback(static fn (array $body): array => ['in' => $body]);
		$zgw->method('getRequestBody')->willReturnCallback(fn (): array => $this->body);
		$zgw->method('buildValidationError')->willReturnCallback(static fn (array $ruleResult): array => ['invalid' => $ruleResult['detail']]);
		$zgw->method('publishNotification')->willReturnCallback(
			function (string $zgwApi, string $resource, string $resourceUrl, string $action): void {
				$this->notifications[] = [$resource, $resourceUrl, $action];
			}
		);

		$sync = $this->createMock(BrcCrossRegisterSync::class);
		foreach (['createOioInDrc', 'deleteOioByDecisionAndIo'] as $method) {
			$sync->method($method)->willReturnCallback(
				function (string $decisionUrl, string $ioUrl) use ($method): void {
					$this->synced[] = [$method, $decisionUrl, $ioUrl];
				}
			);
		}

		return new BrcDocumentLinkHandler(zgwService: $zgw, sync: $sync);
	}//end handler()

	/**
	 * The list is a plain array of mapped links, a hundred at most.
	 *
	 * @return void
	 */
	public function testTheListIsAPlainArray(): void {
		$this->store->searchResult = ['results' => [['id' => 'bio-1'], ['id' => 'bio-2']]];

		$response = $this->handler()->index(request: $this->request);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['out' => ['id' => 'bio-1']], ['out' => ['id' => 'bio-2']]], $response->getData());
		$this->assertSame([[['params' => ['decision' => 'x', '_limit' => 100], 'register' => '7', 'schema' => '71']]], $this->store->callsOf('search'));

		$this->store->failOn = ['search'];
		$failed = $this->handler()->index(request: $this->request);
		$this->assertSame(500, $failed->getStatus());
		$this->assertSame(['detail' => 'Internal server error'], $failed->getData());
		$this->assertSame(['BRC list BIO error: search is down'], $this->errors);
	}//end testTheListIsAPlainArray()

	/**
	 * A create stores the mapped body, asks for its mirror and announces the new link.
	 *
	 * @return void
	 */
	public function testACreateStoresMirrorsAndAnnounces(): void {
		$this->body = ['decision' => 'b-url', 'informatieobject' => 'io-url'];
		$this->store->saved = ['id' => self::BIO];

		$response = $this->handler()->create(request: $this->request);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['out' => ['id' => self::BIO]], $response->getData());
		$this->assertSame([['7', '71', ['in' => $this->body]]], $this->store->callsOf('saveObject'));
		$this->assertSame([['createOioInDrc', 'b-url', 'io-url']], $this->synced);
		$this->assertSame([['besluitinformatieobjecten', self::BASE . 'besluitinformatieobjecten/' . self::BIO, 'create']], $this->notifications);
	}//end testACreateStoresMirrorsAndAnnounces()

	/**
	 * A create asks for no mirror without both URLs, stores nothing when the rules refuse, and
	 * answers 400 with the cause when the store fails.
	 *
	 * @return void
	 */
	public function testACreateThatIsIncompleteRefusedOrFailing(): void {
		$this->body = ['decision' => 'b-url'];
		$this->assertSame(201, $this->handler()->create(request: $this->request)->getStatus());
		$this->assertSame([], $this->synced);

		$this->ruleResult = ['valid' => false, 'status' => 422, 'detail' => 'nee'];
		$refused = $this->handler()->create(request: $this->request);
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame(['invalid' => 'nee'], $refused->getData());
		$this->assertCount(1, $this->store->callsOf('saveObject'));

		$this->ruleResult = null;
		$this->store->failOn = ['saveObject'];
		$failed = $this->handler()->create(request: $this->request);
		$this->assertSame(400, $failed->getStatus());
		$this->assertSame(['detail' => 'saveObject is down'], $failed->getData());
		$this->assertSame(['BRC create BIO error: saveObject is down'], $this->errors);
		$this->assertCount(1, $this->notifications);
	}//end testACreateThatIsIncompleteRefusedOrFailing()

	/**
	 * A delete reads the link, deletes it, asks for its mirror to go and announces it.
	 *
	 * @return void
	 */
	public function testADeleteTakesTheMirrorAlong(): void {
		$this->store->objects[self::BIO] = ['decision' => 'b-uuid', 'document' => 'io-url'];

		$response = $this->handler()->destroy(request: $this->request, uuid: self::BIO);

		$this->assertSame(204, $response->getStatus());
		$this->assertSame([[self::BIO, '7', '71']], $this->store->callsOf('find'));
		$this->assertSame([[self::BIO, true, true]], $this->store->callsOf('deleteObject'));
		$this->assertSame([['deleteOioByDecisionAndIo', self::BASE . 'besluiten/b-uuid', 'io-url']], $this->synced);
		$this->assertSame([['besluitinformatieobjecten', self::BASE . 'besluitinformatieobjecten/' . self::BIO, 'destroy']], $this->notifications);
	}//end testADeleteTakesTheMirrorAlong()

	/**
	 * A delete leaves the mirror alone when the link names no besluit, and answers 404 for a link
	 * that is not there.
	 *
	 * @return void
	 */
	public function testADeleteWithoutABesluitOrWithoutALink(): void {
		$this->store->objects[self::BIO] = ['document' => 'io-url'];
		$this->assertSame(204, $this->handler()->destroy(request: $this->request, uuid: self::BIO)->getStatus());
		$this->assertSame([], $this->synced);

		$missing = $this->handler()->destroy(request: $this->request, uuid: 'gone');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['detail' => 'Not found'], $missing->getData());
		$this->assertSame(['BRC delete BIO error: not found'], $this->errors);
	}//end testADeleteWithoutABesluitOrWithoutALink()

	/**
	 * Every route answers 503 without a store and 404 without the mapping, and touches nothing.
	 *
	 * @return void
	 */
	public function testEveryRouteNeedsAStoreAndAMapping(): void {
		$routes = [
			fn (): JSONResponse => $this->handler()->index(request: $this->request),
			fn (): JSONResponse => $this->handler()->create(request: $this->request),
			fn (): JSONResponse => $this->handler()->destroy(request: $this->request, uuid: self::BIO),
		];

		$this->mapping = null;
		foreach ($routes as $route) {
			$this->assertSame(404, $route()->getStatus());
		}

		$this->assertSame([], $this->store->calls);
		$this->store = null;
		foreach ($routes as $route) {
			$this->assertSame(503, $route()->getStatus());
		}

		$this->assertSame([], $this->notifications);
	}//end testEveryRouteNeedsAStoreAndAMapping()
}//end class
