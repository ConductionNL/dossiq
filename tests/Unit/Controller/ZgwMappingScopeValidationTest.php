<?php

/**
 * The admin form is where an unsearchable ZGW mapping gets in.
 *
 * "Source Register" and "Source Schema" are free-text fields labelled
 * "Register ID" and "Schema ID", and `update()` persisted whatever arrived.
 * A slug is a reasonable thing to type there: dossiq's own `find()` and
 * `saveObject()` accept one. The search path does not, and it says so by
 * returning an empty page rather than an error, so the symptom is "the
 * register is empty" on every ZGW read while every ZGW write keeps working.
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

use OCA\Dossiq\Controller\ZgwMappingController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What `ZgwMappingController::update()` accepts as a source register/schema.
 */
final class ZgwMappingScopeValidationTest extends TestCase {
	/**
	 * The mapping store, watched for writes.
	 *
	 * @var ZgwMappingService|MockObject
	 */
	private ZgwMappingService $mappingService;

	/**
	 * The controller under test.
	 *
	 * @var ZgwMappingController
	 */
	private ZgwMappingController $controller;

	/**
	 * Build the controller over a request carrying the given mapping.
	 *
	 * @param array<string, mixed> $params The posted mapping fields.
	 *
	 * @return void
	 */
	private function postMapping(array $params): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(($params + ['_route' => 'x', 'resourceKey' => 'zaak']));

		$this->mappingService = $this->createMock(ZgwMappingService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new ZgwMappingController(
			request: $request,
			zgwMappingService: $this->mappingService,
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
		);
	}//end postMapping()

	/**
	 * A mapping naming numeric ids is stored, as it always was.
	 *
	 * @return void
	 */
	public function testNumericIdsAreStored(): void {
		$this->postMapping(params: ['sourceRegister' => '12', 'sourceSchema' => '34']);
		$this->mappingService->expects($this->once())->method('saveMapping');

		$response = $this->controller->update(resourceKey: 'zaak');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testNumericIdsAreStored()

	/**
	 * A mapping naming its schema by slug is refused, not stored.
	 *
	 * @return void
	 */
	public function testASlugSchemaIsRefused(): void {
		$this->postMapping(params: ['sourceRegister' => '12', 'sourceSchema' => 'zaak']);
		$this->mappingService->expects($this->never())->method('saveMapping');

		$response = $this->controller->update(resourceKey: 'zaak');

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$response->getStatus(),
			'A mapping that reads as an empty register was accepted.'
		);
		$this->assertFalse($response->getData()['success']);
	}//end testASlugSchemaIsRefused()

	/**
	 * A mapping naming its register by slug is refused too.
	 *
	 * @return void
	 */
	public function testASlugRegisterIsRefused(): void {
		$this->postMapping(params: ['sourceRegister' => 'dossiq', 'sourceSchema' => '34']);
		$this->mappingService->expects($this->never())->method('saveMapping');

		$response = $this->controller->update(resourceKey: 'zaak');

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$response->getStatus(),
			'A mapping that reads as an empty register was accepted.'
		);
	}//end testASlugRegisterIsRefused()

	/**
	 * An empty schema is refused: it is the same zero by another route.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaIsRefused(): void {
		$this->postMapping(params: ['sourceRegister' => '12', 'sourceSchema' => '']);
		$this->mappingService->expects($this->never())->method('saveMapping');

		$response = $this->controller->update(resourceKey: 'zaak');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnEmptySchemaIsRefused()

	/**
	 * A mapping that reads no objects at all keeps working.
	 *
	 * `applicatie` maps OpenRegister consumers, not objects, and names neither
	 * field. Refusing it would break the one mapping this guard has nothing to
	 * say about.
	 *
	 * @return void
	 */
	public function testAMappingWithoutASourceIsStored(): void {
		$this->postMapping(params: ['zgwResource' => 'applicatie', 'enabled' => true]);
		$this->mappingService->expects($this->once())->method('saveMapping');

		$response = $this->controller->update(resourceKey: 'applicatie');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAMappingWithoutASourceIsStored()
}//end class
