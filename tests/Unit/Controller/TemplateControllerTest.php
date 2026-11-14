<?php

/**
 * TemplateController Unit Tests.
 *
 * Pins the authentication guard on every action, the not-found answer for an
 * unknown template, and the 400 for a template the library refuses to activate.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TemplateController;
use OCA\Dossiq\Service\TemplateLibraryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for TemplateController.
 *
 * @covers \OCA\Dossiq\Controller\TemplateController
 */
class TemplateControllerTest extends TestCase {

	/**
	 * The template library double.
	 *
	 * @var TemplateLibraryService&MockObject
	 */
	private TemplateLibraryService&MockObject $templates;

	/**
	 * Set up the library double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->templates = $this->createMock(TemplateLibraryService::class);
	}//end setUp()

	/**
	 * Build the controller with or without a signed-in user.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return TemplateController
	 */
	private function controller(bool $signedIn): TemplateController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new TemplateController('dossiq', $this->createMock(IRequest::class), $this->templates, $session);
	}//end controller()

	/**
	 * Every action answers 401 without a user and never reaches the library.
	 *
	 * @return void
	 */
	public function testEveryActionRequiresAUser(): void {
		$this->templates->expects($this->never())->method($this->anything());
		$controller = $this->controller(signedIn: false);

		foreach ([$controller->index(), $controller->show('t-1'), $controller->activate('t-1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testEveryActionRequiresAUser()

	/**
	 * The index wraps the library's list in a results member.
	 *
	 * @return void
	 */
	public function testIndexListsTheTemplates(): void {
		$this->templates->method('listTemplates')->willReturn([['id' => 'bouw'], ['id' => 'horeca']]);

		$response = $this->controller(signedIn: true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'bouw'], ['id' => 'horeca']]], $response->getData());
	}//end testIndexListsTheTemplates()

	/**
	 * A known template is returned as is; an unknown one is a 404.
	 *
	 * @return void
	 */
	public function testShowReturnsTheTemplateOr404(): void {
		$this->templates->method('loadTemplate')->willReturnCallback(
			static fn (string $id): ?array => ($id === 'bouw' ? ['id' => 'bouw', 'name' => 'Bouw'] : null)
		);
		$controller = $this->controller(signedIn: true);

		$found = $controller->show('bouw');
		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(['id' => 'bouw', 'name' => 'Bouw'], $found->getData());

		$missing = $controller->show('nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Template not found'], $missing->getData());
	}//end testShowReturnsTheTemplateOr404()

	/**
	 * Activation returns the library's result for the asked template.
	 *
	 * @return void
	 */
	public function testActivateReturnsTheResult(): void {
		$this->templates->expects($this->once())->method('activateTemplate')->with('bouw')->willReturn(['activated' => 'bouw']);

		$response = $this->controller(signedIn: true)->activate('bouw');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['activated' => 'bouw'], $response->getData());
	}//end testActivateReturnsTheResult()

	/**
	 * A refused activation is a 400 carrying the library's message.
	 *
	 * @return void
	 */
	public function testARefusedActivationIs400(): void {
		$this->templates->method('activateTemplate')->willThrowException(new RuntimeException('Template already active'));

		$response = $this->controller(signedIn: true)->activate('bouw');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Template already active'], $response->getData());
	}//end testARefusedActivationIs400()
}//end class
