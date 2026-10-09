<?php

/**
 * MenuCaseTypesController Unit Tests
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
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\MenuCaseTypesController;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for MenuCaseTypesController.
 *
 * @covers \OCA\Dossiq\Controller\MenuCaseTypesController
 */
class MenuCaseTypesControllerTest extends TestCase {

	/**
	 * Build the controller over a service mock for a user (or anonymous).
	 *
	 * @param MenuCaseTypesService $service The service mock.
	 * @param string|null $userId The user, or null for anonymous.
	 *
	 * @return MenuCaseTypesController
	 */
	private function controller(MenuCaseTypesService $service, ?string $userId='alice'): MenuCaseTypesController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		return new MenuCaseTypesController(
			request: $this->createMock(IRequest::class),
			menuCaseTypes: $service,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * index: the user's own chosen list and the available case types, with their open cases.
	 *
	 * The counts are read once, for the offered case types, and the chosen
	 * list is cut from that same answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testIndexReturnsChosenAndAvailable(): void {
		$visible = [['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']];
		$counted = [['id' => 'a', 'title' => 'A', 'openCases' => 4], ['id' => 'b', 'title' => 'B', 'openCases' => 0]];
		$service = $this->createMock(MenuCaseTypesService::class);
		$service->method('offeredCaseTypes')->with('alice')->willReturn($visible);
		$service->expects($this->once())->method('withOpenCaseCounts')->with($visible)->willReturn($counted);
		$service->expects($this->once())->method('chosen')->with('alice', $counted)->willReturn([$counted[1]]);

		$response = $this->controller($service)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['chosen' => [$counted[1]], 'available' => $counted], $response->getData());
	}//end testIndexReturnsChosenAndAvailable()

	/**
	 * index: a count query that fails leaves the picker working, with no numbers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testAFailedCountShowsNoNumbers(): void {
		$visible = [['id' => 'a', 'title' => 'A']];
		$unknown = [['id' => 'a', 'title' => 'A', 'openCases' => null]];
		$service = $this->createMock(MenuCaseTypesService::class);
		$service->method('offeredCaseTypes')->willReturn($visible);
		$service->method('withOpenCaseCounts')->willThrowException(new RuntimeException('facet store down'));
		$service->expects($this->once())->method('withUnknownOpenCaseCounts')->with($visible)->willReturn($unknown);
		$service->method('chosen')->willReturn($unknown);

		$response = $this->controller($service)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['chosen' => $unknown, 'available' => $unknown], $response->getData());
	}//end testAFailedCountShowsNoNumbers()

	/**
	 * update: stores for the current user only, through the cleaning service.
	 *
	 * @return void
	 */
	public function testUpdateSavesForTheCurrentUser(): void {
		$visible = [['id' => 'a', 'title' => 'A']];
		$service = $this->createMock(MenuCaseTypesService::class);
		$service->method('offeredCaseTypes')->with('alice')->willReturn($visible);
		$service->expects($this->once())->method('save')->with('alice', ['a', 'x'], $visible)->willReturn($visible);

		$response = $this->controller($service)->update(['a', 'x']);

		$this->assertSame(['chosen' => $visible], $response->getData());
	}//end testUpdateSavesForTheCurrentUser()

	/**
	 * Anonymous callers are refused on both verbs.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$service = $this->createMock(MenuCaseTypesService::class);
		$service->expects($this->never())->method('save');

		$controller = $this->controller($service, null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->update(['a'])->getStatus());
	}//end testAnonymousIsRefused()
}//end class
