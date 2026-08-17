<?php

/**
 * DashboardController Wire-Contract Tests
 *
 * Contract coverage for the two SPA-shell endpoints (gate-25): `page` (`GET /`)
 * and `catchAll` (`GET /{path}`, the Vue history-mode deep-link route).
 *
 * These two carry no service call to assert, so the contract IS the response
 * envelope and the auth posture — and both are load-bearing:
 *
 *  - the class docblock records that `#[NoAdminRequired]` / `#[NoCSRFRequired]`
 *    used to be INHERITED from the OpenRegister AppHost generic and were
 *    re-declared locally when the inheritance was dropped. Nothing else proves
 *    they survived that edit, and an endpoint that loses `#[NoAdminRequired]`
 *    silently becomes admin-only — the SPA would 403 for every ordinary user
 *    while every unit test still passed;
 *  - neither may be `#[PublicPage]`: the shell must require a Nextcloud
 *    session. Adding `#[PublicPage]` here would serve the whole procest SPA
 *    chrome anonymously;
 *  - `catchAll` must render the SAME template as `page` and render it AS A USER
 *    (`RENDER_AS_USER`) with a 200 — a deep link that answered 404, redirected,
 *    or rendered the guest/public chrome would break every bookmarked URL in
 *    the app.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DashboardController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for DashboardController's SPA shell endpoints.
 *
 * @covers \OCA\Procest\Controller\DashboardController
 */
class DashboardControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The controller under test.
	 *
	 * @var DashboardController
	 */
	private DashboardController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->controller = new DashboardController(request: $this->request);
	}//end setUp()

	/**
	 * Collect the attribute class names declared on a controller method.
	 *
	 * @param string $method The method name.
	 *
	 * @return array<int, string> The attribute class names.
	 */
	private function attributeNamesOf(string $method): array {
		$reflection = new \ReflectionMethod(DashboardController::class, $method);

		return array_map(
			static function (\ReflectionAttribute $attribute): string {
				return $attribute->getName();
			},
			$reflection->getAttributes()
		);
	}//end attributeNamesOf()

	/**
	 * `page` renders the procest `index` template as a signed-in user, with 200.
	 *
	 * @return void
	 */
	public function testPageRendersTheProcestIndexTemplateAsAUser(): void {
		$response = $this->controller->page();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('procest', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPageRendersTheProcestIndexTemplateAsAUser()

	/**
	 * `catchAll` serves the SAME shell as `page` — a deep link must reach the
	 * Vue router, not a 404 or a different chrome.
	 *
	 * @return void
	 */
	public function testCatchAllServesTheSameShellAsPageSoDeepLinksResolve(): void {
		$page = $this->controller->page();
		$deepLink = $this->controller->catchAll();

		$this->assertInstanceOf(TemplateResponse::class, $deepLink);
		$this->assertSame($page->getApp(), $deepLink->getApp());
		$this->assertSame($page->getTemplateName(), $deepLink->getTemplateName());
		$this->assertSame($page->getRenderAs(), $deepLink->getRenderAs());
		$this->assertSame(Http::STATUS_OK, $deepLink->getStatus());
	}//end testCatchAllServesTheSameShellAsPageSoDeepLinksResolve()

	/**
	 * Both shell endpoints keep the `#[NoAdminRequired]` / `#[NoCSRFRequired]`
	 * posture they used to inherit from the AppHost generic.
	 *
	 * Losing `#[NoAdminRequired]` turns the whole SPA admin-only; losing
	 * `#[NoCSRFRequired]` makes a plain browser navigation to `/` fail its CSRF
	 * check. Neither shows up in any behavioural assertion.
	 *
	 * @return void
	 */
	public function testBothShellEndpointsKeepTheirDeclaredAuthPosture(): void {
		foreach (['page', 'catchAll'] as $method) {
			$attributes = $this->attributeNamesOf(method: $method);

			$this->assertContains(
				NoAdminRequired::class,
				$attributes,
				$method . ' must stay reachable by non-admin users'
			);
			$this->assertContains(
				NoCSRFRequired::class,
				$attributes,
				$method . ' is a browser navigation and carries no CSRF token'
			);
		}
	}//end testBothShellEndpointsKeepTheirDeclaredAuthPosture()

	/**
	 * Neither shell endpoint may be public: the procest SPA requires a session.
	 *
	 * @return void
	 */
	public function testNeitherShellEndpointIsAPublicPage(): void {
		$this->assertNotContains(
			PublicPage::class,
			$this->attributeNamesOf(method: 'page'),
			'the SPA shell must not be reachable without a Nextcloud session'
		);
		$this->assertNotContains(
			PublicPage::class,
			$this->attributeNamesOf(method: 'catchAll'),
			'the SPA deep-link route must not be reachable without a Nextcloud session'
		);
	}//end testNeitherShellEndpointIsAPublicPage()
}//end class
