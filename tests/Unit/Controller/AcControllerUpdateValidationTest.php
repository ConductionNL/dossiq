<?php

/**
 * AcController Update Validation Tests
 *
 * PUT/PATCH on an applicatie used to skip the AC business rules that POST
 * enforces (ac-001 clientId uniqueness, ac-002 heeftAlleAutorisaties
 * consistency, ac-003 scope-based field requirements), so an update could
 * write a grant that create would have refused. These tests pin that update
 * now runs the same validation, and that the applicatie being updated is
 * excluded from its own clientId uniqueness check.
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

use OCA\Dossiq\Controller\AcController;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that AcController::update() applies the AC business rules.
 */
class AcControllerUpdateValidationTest extends TestCase {

	/**
	 * The ZgwService mock.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The fake consumer mapper.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * The controller under test.
	 *
	 * @var AcController
	 */
	private AcController $controller;

	/**
	 * Build the controller with an authorised caller and two stored consumers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer a.b.c');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->mapper = new class([
			self::consumer(uuid: 'uuid-self', name: 'client-self'),
			self::consumer(uuid: 'uuid-other', name: 'client-other'),
		]) {
			/**
			 * Entities passed to update().
			 *
			 * @var array<int, object>
			 */
			public array $updated = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, object> $consumers The stored consumers.
			 */
			public function __construct(private array $consumers) {
			}

			/**
			 * Find consumers, optionally by uuid.
			 *
			 * @param array<string, string> $filters The filters.
			 *
			 * @return array<int, object>
			 */
			public function findAll(array $filters = []): array {
				if (isset($filters['uuid']) === false) {
					return $this->consumers;
				}

				return array_values(
					array_filter(
						$this->consumers,
						static fn (object $c): bool => $c->jsonSerialize()['uuid'] === $filters['uuid']
					)
				);
			}

			/**
			 * Record an update.
			 *
			 * @param object $entity The entity.
			 *
			 * @return object
			 */
			public function update(object $entity): object {
				$this->updated[] = $entity;
				return $entity;
			}
		};

		$this->zgwService = $this->createMock(ZgwService::class);
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturn(true);
		$this->zgwService->method('getConsumerMapper')->willReturn($this->mapper);
		$this->zgwService->method('buildBaseUrl')->willReturn('https://example.test/applicaties');
		$this->zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));

		$this->controller = new AcController(
			appName: 'dossiq',
			request: $request,
			zgwService: $this->zgwService,
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * A minimal consumer entity stub.
	 *
	 * @param string $uuid The consumer uuid.
	 * @param string $name The primary clientId.
	 *
	 * @return object
	 */
	private static function consumer(string $uuid, string $name): object {
		return new class($uuid, $name) {
			/**
			 * Authorization configuration.
			 *
			 * @var array<string, mixed>
			 */
			private array $authConfig = ['superuser' => false, 'scopes' => []];

			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid.
			 * @param string $name The name.
			 */
			public function __construct(private string $uuid, private string $name) {
			}

			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => $this->uuid, 'name' => $this->name, 'description' => ''];
			}

			/**
			 * Authorization configuration.
			 *
			 * @return array<string, mixed>
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->authConfig;
			}

			/**
			 * Set the name.
			 *
			 * @param string $name The name.
			 *
			 * @return void
			 */
			public function setName(string $name): void {
				$this->name = $name;
			}

			/**
			 * Set the authorization configuration.
			 *
			 * @param array<string, mixed> $config The configuration.
			 *
			 * @return void
			 */
			public function setAuthorizationConfiguration(array $config): void {
				$this->authConfig = $config;
			}
		};
	}//end consumer()

	/**
	 * Updating to a clientId another applicatie already uses is refused (ac-001).
	 *
	 * @return void
	 */
	public function testUpdateRejectsAClientIdUsedByAnotherApplicatie(): void {
		$this->zgwService->method('getRequestBody')->willReturn([
			'clientIds' => ['client-other'],
			'heeftAlleAutorisaties' => false,
			'autorisaties' => [['component' => 'nrc', 'scopes' => ['notificaties.lezen']]],
		]);

		$response = $this->controller->update(uuid: 'uuid-self');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('clientId-exists', $response->getData()['invalidParams'][0]['code']);
		$this->assertSame([], $this->mapper->updated);
	}//end testUpdateRejectsAClientIdUsedByAnotherApplicatie()

	/**
	 * Updating a zaken-scoped autorisatie without zaaktype is refused (ac-003).
	 *
	 * @return void
	 */
	public function testUpdateRejectsAZakenScopeWithoutRequiredFields(): void {
		$this->zgwService->method('getRequestBody')->willReturn([
			'clientIds' => ['client-self'],
			'heeftAlleAutorisaties' => false,
			'autorisaties' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]],
		]);

		$response = $this->controller->update(uuid: 'uuid-self');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->mapper->updated);
	}//end testUpdateRejectsAZakenScopeWithoutRequiredFields()

	/**
	 * Keeping the applicatie's OWN clientId is not a uniqueness conflict.
	 *
	 * @return void
	 */
	public function testUpdateKeepingItsOwnClientIdSucceeds(): void {
		$this->zgwService->method('getRequestBody')->willReturn([
			'clientIds' => ['client-self'],
			'heeftAlleAutorisaties' => false,
			'autorisaties' => [['component' => 'nrc', 'scopes' => ['notificaties.lezen']]],
		]);

		$response = $this->controller->update(uuid: 'uuid-self');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->mapper->updated);
	}//end testUpdateKeepingItsOwnClientIdSucceeds()
}//end class
