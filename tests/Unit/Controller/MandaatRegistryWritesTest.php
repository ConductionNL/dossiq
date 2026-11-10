<?php

/**
 * Mandate registry writes, characterised.
 *
 * MandaatRegistryController and OrganisatieRolController save registry
 * objects from the request body the same way: the route and identity keys
 * are dropped, a create answers 201 and an update 200, a refused save 422
 * with its message and any other failure a logged 500.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use LogicException;
use OCA\Dossiq\Controller\MandaatRegistryController;
use OCA\Dossiq\Controller\OrganisatieRolController;
use OCA\Dossiq\Service\Mandaat\MandaatRegistryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Create and update of every registry object the two controllers write.
 *
 * @covers \OCA\Dossiq\Controller\MandaatRegistryController
 * @covers \OCA\Dossiq\Controller\OrganisatieRolController
 * @covers \OCA\Dossiq\Controller\Support\RegistryObjectWrite
 */
class MandaatRegistryWritesTest extends TestCase {

	/**
	 * The registry service.
	 *
	 * @var MandaatRegistryService&MockObject
	 */
	private MandaatRegistryService&MockObject $registry;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->createMock(MandaatRegistryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The writes: controller, create action, update action, schema key.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
	 */
	public static function writes(): array {
		return [
			'mandaat' => [MandaatRegistryController::class, 'mandatenCreate', 'mandatenUpdate', MandaatRegistryService::SCHEMA_MANDAAT],
			'rol' => [OrganisatieRolController::class, 'rollenCreate', 'rollenUpdate', MandaatRegistryService::SCHEMA_ROL],
			'toewijzing' => [OrganisatieRolController::class, 'toewijzingenCreate', 'toewijzingenUpdate', MandaatRegistryService::SCHEMA_TOEWIJZING],
		];
	}//end writes()

	/**
	 * One controller over a request with this body.
	 *
	 * @param string $class The controller class.
	 *
	 * @return Controller
	 */
	private function controller(string $class): Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(
			['_route' => 'r', 'id' => 'x', 'uuid' => 'y', '@self' => ['id' => 'z'], 'naam' => 'Wethouder', 'actief' => true]
		);

		return new $class('dossiq', $request, $this->registry, $this->logger);
	}//end controller()

	/**
	 * A create saves the body without route and identity keys and answers 201.
	 *
	 * @param string $class  The controller class.
	 * @param string $create The create action.
	 * @param string $update The update action.
	 * @param string $key    The schema config key.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testACreateAnswers201(string $class, string $create, string $update, string $key): void {
		$this->registry->expects($this->once())->method('save')
			->with($key, ['naam' => 'Wethouder', 'actief' => true], null)
			->willReturn(['id' => 'new']);

		$response = $this->controller($class)->$create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'new'], $response->getData());
	}//end testACreateAnswers201()

	/**
	 * An update saves under its id and answers 200.
	 *
	 * @param string $class  The controller class.
	 * @param string $create The create action.
	 * @param string $update The update action.
	 * @param string $key    The schema config key.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAnUpdateAnswers200(string $class, string $create, string $update, string $key): void {
		$this->registry->expects($this->once())->method('save')
			->with($key, ['naam' => 'Wethouder', 'actief' => true], 'obj-1')
			->willReturn(['id' => 'obj-1']);

		$response = $this->controller($class)->$update('obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'obj-1'], $response->getData());
	}//end testAnUpdateAnswers200()

	/**
	 * A refused save is a 422 with its message, not logged.
	 *
	 * @param string $class  The controller class.
	 * @param string $create The create action.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testARefusedSaveIs422(string $class, string $create): void {
		$this->registry->method('save')->willThrowException(new RuntimeException('naam is required'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller($class)->$create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'naam is required'], $response->getData());
	}//end testARefusedSaveIs422()

	/**
	 * Any other failure is a logged 500.
	 *
	 * @param string $class  The controller class.
	 * @param string $create The create action.
	 * @param string $update The update action.
	 * @param string $key    The schema config key.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsALogged500(string $class, string $create, string $update, string $key): void {
		$this->registry->method('save')->willThrowException(new LogicException('db gone'));
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to save mandate registry object (' . $key . '): db gone',
			['app' => 'dossiq']
		);

		$response = $this->controller($class)->$update('obj-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Failed to save: db gone'], $response->getData());
	}//end testAnyOtherFailureIsALogged500()
}//end class
