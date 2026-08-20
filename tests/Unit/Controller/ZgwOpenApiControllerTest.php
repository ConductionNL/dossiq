<?php

/**
 * ZgwOpenApiController Unit Tests
 *
 * Verifies the discovery index lists all six ZGW APIs with resolvable spec
 * URLs, that spec() serves each known API's YAML document with the correct
 * content type, that an unknown api id 404s, and that both endpoints carry
 * the public, no-CSRF auth posture required for unauthenticated
 * documentation access (spec Requirement: Public, read-only discovery).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/zgw-openapi-publication/specs/zgw-openapi-publication/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ZgwOpenApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ZgwOpenApiController.
 *
 * @covers \OCA\Procest\Controller\ZgwOpenApiController
 */
class ZgwOpenApiControllerTest extends TestCase {

	/**
	 * The six documented ZGW API ids.
	 *
	 * @var array<int, string>
	 */
	private const APIS = ['zaken', 'documenten', 'catalogi', 'besluiten', 'autorisaties', 'notificaties'];

	/**
	 * The mocked request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The controller under test.
	 *
	 * @var ZgwOpenApiController
	 */
	private ZgwOpenApiController $controller;

	/**
	 * Set up the controller with a mocked request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('procest.example.org');

		$this->controller = new ZgwOpenApiController(request: $this->request);
	}//end setUp()

	/**
	 * index() lists exactly the six ZGW APIs, each with an id, name,
	 * basePath, standard and a resolvable specUrl.
	 *
	 * @return void
	 */
	public function testIndexListsSixApisWithSpecUrls(): void {
		$response = $this->controller->index();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertIsArray($data);
		self::assertArrayHasKey('apis', $data);
		self::assertCount(6, $data['apis']);

		$ids = array_column($data['apis'], 'id');
		self::assertSame(self::APIS, $ids);

		foreach ($data['apis'] as $api) {
			self::assertArrayHasKey('id', $api);
			self::assertArrayHasKey('name', $api);
			self::assertArrayHasKey('basePath', $api);
			self::assertArrayHasKey('standard', $api);
			self::assertArrayHasKey('specUrl', $api);
			self::assertSame('VNG ZGW 1.x', $api['standard']);
			self::assertStringEndsWith(
				'/apps/procest/api/zgw/' . $api['id'] . '/openapi.yaml',
				$api['specUrl']
			);
		}
	}//end testIndexListsSixApisWithSpecUrls()

	/**
	 * spec() returns the YAML document with an application/yaml content
	 * type for every known API id.
	 *
	 * @return void
	 */
	public function testSpecReturnsYamlForEveryKnownApi(): void {
		foreach (self::APIS as $api) {
			$response = $this->controller->spec(api: $api);

			self::assertInstanceOf(DataDisplayResponse::class, $response, 'api: ' . $api);
			self::assertSame(Http::STATUS_OK, $response->getStatus());
			self::assertSame('application/yaml', $response->getHeaders()['Content-Type'] ?? null);
			self::assertStringContainsString('openapi: 3.0.3', (string)$response->getData());
		}
	}//end testSpecReturnsYamlForEveryKnownApi()

	/**
	 * spec() 404s for an api id outside the allow-list (no path traversal).
	 *
	 * @return void
	 */
	public function testSpecReturns404ForUnknownApi(): void {
		$response = $this->controller->spec(api: 'bogus');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSpecReturns404ForUnknownApi()

	/**
	 * spec() 404s for a path-traversal attempt, never touching the
	 * filesystem outside docs/openapi/zgw/.
	 *
	 * @return void
	 */
	public function testSpecReturns404ForPathTraversalAttempt(): void {
		$response = $this->controller->spec(api: '../../../../etc/passwd');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSpecReturns404ForPathTraversalAttempt()

	/**
	 * Both index() and spec() are annotated @PublicPage and @NoCSRFRequired
	 * so the discovery surface is reachable without authentication (spec
	 * Requirement: Public, read-only discovery).
	 *
	 * @return void
	 */
	public function testIndexAndSpecAreAnnotatedPublicAndNoCsrf(): void {
		foreach (['index', 'spec'] as $method) {
			$reflection = new \ReflectionMethod(ZgwOpenApiController::class, $method);
			$docComment = $reflection->getDocComment();

			self::assertIsString($docComment, 'Missing doc comment on ' . $method . '()');
			self::assertStringContainsString('@PublicPage', $docComment, $method . '() must be @PublicPage');
			self::assertStringContainsString('@NoCSRFRequired', $docComment, $method . '() must be @NoCSRFRequired');
		}
	}//end testIndexAndSpecAreAnnotatedPublicAndNoCsrf()
}//end class
