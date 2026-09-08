<?php

/**
 * Wire-contract tests for CaseTypeController.
 *
 * The two endpoints do NOT share an auth posture, and that difference is what
 * these pin. `blueprint` is a READ that the case page, the stepper and the
 * new-case form all perform as ordinary users, so an ordinary session must
 * reach it. `publish` and `validatePublish` carry the same
 * `#[NoAdminRequired]` attribute — which is what lets an ordinary session
 * reach the METHOD at all — and refuse in the body. A test that only checked
 * the attribute would call that authorised.
 *
 * 🔴 THE REALISTIC DEFECT IS THE ONE THIS GUARDS: `#[NoAdminRequired]` reads,
 * to a hurried reviewer, like "anyone may". Without the body guard, publishing
 * a case type would be open to every authenticated user, and gate-5
 * (route-auth) would still pass because an auth attribute is present.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTypeController;
use OCA\Dossiq\Service\CaseTypePublishService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class CaseTypeControllerTest extends TestCase {

	/**
	 * A controller with the collaborators a test wants.
	 *
	 * @param CaseTypeResolver|null       $resolver The blueprint resolver.
	 * @param CaseTypePublishService|null $publish  The publish service.
	 * @param string                      $note     The change note on the request.
	 *
	 * @return CaseTypeController The controller.
	 */
	private function controller(
		?CaseTypeResolver $resolver = null,
		?CaseTypePublishService $publish = null,
		string $note = '',
	): CaseTypeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($note);

		return new CaseTypeController(
			'dossiq',
			$request,
			($resolver ?? $this->createMock(CaseTypeResolver::class)),
			($publish ?? $this->createMock(CaseTypePublishService::class)),
			new NullLogger()
		);
	}//end controller()

	/**
	 * A resolver answering one blueprint.
	 *
	 * @param array<string, mixed> $blueprint What blueprintFor() returns.
	 *
	 * @return CaseTypeResolver The double.
	 */
	private function resolverAnswering(array $blueprint): CaseTypeResolver {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('blueprintFor')->willReturn($blueprint);

		return $resolver;
	}//end resolverAnswering()

	/**
	 * The blueprint of a readable case type comes back whole.
	 */
	public function testTheBlueprintIsReturned(): void {
		$blueprint = [
			'caseType' => ['id' => 'ct', 'title' => 'Bezwaar'],
			'parents' => [],
			'statusTypes' => [['id' => 's1', 'name' => 'Ontvangen', 'origin' => 'own']],
			'resultTypes' => [],
			'propertyDefinitions' => [],
		];

		$response = $this->controller(resolver: $this->resolverAnswering($blueprint))
			->blueprint(id: 'ct');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($blueprint, $response->getData());
	}//end testTheBlueprintIsReturned()

	/**
	 * 🔴 AN EMPTY BLUEPRINT IS A 404, NOT A 200 WITH EMPTY LISTS.
	 *
	 * A 200 carrying empty lists is indistinguishable, to the page, from a
	 * case type that genuinely has nothing on it — and the page would say
	 * "this case type has no statuses yet" about a case type that does not
	 * exist.
	 */
	public function testAnUnreadableCaseTypeIsNotFound(): void {
		$response = $this->controller(resolver: $this->resolverAnswering(['caseType' => []]))
			->blueprint(id: 'gone');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnreadableCaseTypeIsNotFound()

	/**
	 * Reading a blueprint needs no admin: the case page does it.
	 */
	public function testReadingABlueprintNeedsNoAdmin(): void {
		self::assertSame(
			['OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired'],
			$this->authAttributesOf(method: 'blueprint')
		);
	}//end testReadingABlueprintNeedsNoAdmin()

	/**
	 * 🔴 THE AUTHORITY IS THE ATTRIBUTE, NOT A GUARD IN THE BODY.
	 *
	 * These were `#[NoAdminRequired]` plus a `requireAdmin()` in the body, and
	 * hydra gate-9 (semantic-auth) refused the pair: an attribute saying "any
	 * authenticated user may reach this" over a body that admits only admins
	 * is exactly the mismatch that gate exists for, and it reads to a reviewer
	 * as an endpoint anyone may call. `AuthorizedAdminSetting` says what is
	 * true, and Nextcloud's middleware enforces it before the method runs.
	 *
	 * Asserted on the ATTRIBUTE rather than on a 403, because there is no
	 * longer a body to answer 403 from: the check happens before the method.
	 */
	public function testPublishingIsAdminOnlyByAttribute(): void {
		foreach (['publish', 'validatePublish'] as $method) {
			$attributes = $this->authAttributesOf(method: $method);

			self::assertContains(
				'OCP\\AppFramework\\Http\\Attribute\\AuthorizedAdminSetting',
				$attributes,
				$method
			);
			self::assertNotContains(
				'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
				$attributes,
				$method
			);
		}
	}//end testPublishingIsAdminOnlyByAttribute()

	/**
	 * The auth attributes one controller method declares.
	 *
	 * @param string $method The method name.
	 *
	 * @return array<int, string> The attribute class names.
	 */
	private function authAttributesOf(string $method): array {
		$reflection = new \ReflectionMethod(CaseTypeController::class, $method);

		$names = [];
		foreach ($reflection->getAttributes() as $attribute) {
			$names[] = $attribute->getName();
		}

		return $names;
	}//end authAttributesOf()

	/**
	 * A refused publish answers 422 WITH the findings, so the dialog can list them.
	 */
	public function testFindingsComeBackWithA422(): void {
		$publish = $this->createMock(CaseTypePublishService::class);
		$publish->method('publish')->willReturn(
			['published' => false, 'findings' => ['Give the case type at least one status.'], 'version' => null]
		);

		$response = $this->controller(publish: $publish)->publish(id: 'ct');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			['Give the case type at least one status.'],
			$response->getData()['findings']
		);
	}//end testFindingsComeBackWithA422()

	/**
	 * A successful publish answers 200 and the version.
	 */
	public function testAPublishedTypeAnswersItsVersion(): void {
		$publish = $this->createMock(CaseTypePublishService::class);
		$publish->method('publish')->willReturn(
			['published' => true, 'findings' => [], 'version' => 3]
		);

		$response = $this->controller(publish: $publish)->publish(id: 'ct');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(3, $response->getData()['version']);
	}//end testAPublishedTypeAnswersItsVersion()

	/**
	 * An unexpected failure answers 500 WITHOUT the exception text.
	 */
	public function testAnUnexpectedFailureWithholdsItsDetail(): void {
		$publish = $this->createMock(CaseTypePublishService::class);
		$publish->method('publish')->willThrowException(new RuntimeException('mysql said no, at /var/www/secret.php'));

		$response = $this->controller(publish: $publish)->publish(id: 'ct');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('mysql', json_encode($response->getData()));
	}//end testAnUnexpectedFailureWithholdsItsDetail()

	/**
	 * The findings endpoint answers them under a `findings` key.
	 */
	public function testValidateAnswersTheFindings(): void {
		$publish = $this->createMock(CaseTypePublishService::class);
		$publish->method('validate')->willReturn(['Give the case type a title.']);

		$response = $this->controller(publish: $publish)->validatePublish(id: 'ct');

		self::assertSame(['Give the case type a title.'], $response->getData()['findings']);
	}//end testValidateAnswersTheFindings()
}//end class
