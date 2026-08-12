<?php

/**
 * SettingsController write-path unit tests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SettingsController;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The canonical AppHost route table routes BOTH `PUT /api/settings`
 * (`settings#update`) and `POST /api/settings` (`settings#create`) into this
 * controller, and because this app ships the class itself no generic is
 * aliased in to cover either.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters, and
 * that the returned payload carries the service's result. A test that only
 * checked for a 200, or only that the response was a JSONResponse, would pass
 * against a controller that silently wrote nothing.
 *
 * @covers \OCA\Procest\Controller\SettingsController
 */
class SettingsControllerWriteTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Build the controller under test with the collaborators mocked.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function controller(): SettingsController {
		return new SettingsController(
			request: $this->request,
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			settingsService: $this->settingsService,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end controller()

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * PUT /api/settings must persist the request parameters and return them.
	 *
	 * @return void
	 */
	public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void {
		$submitted = ['lhsMatrix' => '{"a":1}'];
		$stored = ['lhsMatrix' => '{"a":1}', 'other' => 'untouched'];

		$this->request->method('getParams')->willReturn($submitted);

		// The ITEM: the write reaches the service, with the submitted params.
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->update();

		$this->assertSame(
			['success' => true, 'config' => $stored],
			$response->getData(),
			'update() must return the config the service actually stored, not the submission'
		);
	}//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

	/**
	 * POST /api/settings is a legacy alias and must write identically.
	 *
	 * Three procest views still POST to this route, so the alias staying a
	 * real write — not an empty success — is load-bearing.
	 *
	 * @return void
	 */
	public function testCreateDelegatesToUpdateAndStillWrites(): void {
		$submitted = ['kccEnabled' => true];
		$stored = ['kccEnabled' => true];

		$this->request->method('getParams')->willReturn($submitted);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->create();

		$this->assertSame(
			['success' => true, 'config' => $stored],
			$response->getData(),
			'create() must produce the same written result as update()'
		);
	}//end testCreateDelegatesToUpdateAndStillWrites()

	/**
	 * The write must not be skipped when the submission is empty.
	 *
	 * An early return on an empty payload would look identical to a
	 * successful no-op write from the caller's side.
	 *
	 * @return void
	 */
	public function testEmptySubmissionStillReachesTheService(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with([])
			->willReturn(['unchanged' => true]);

		$response = $this->controller()->update();

		$this->assertSame(
			['success' => true, 'config' => ['unchanged' => true]],
			$response->getData()
		);
	}//end testEmptySubmissionStillReachesTheService()

}//end class
