<?php

/**
 * The marketplace's shipped packages: who may read and who may install.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StorePackageController;
use OCA\Dossiq\Service\Setup\CaseTypePackageChoice;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Dossiq\Controller\StorePackageController
 *
 * @spec openspec/changes/marketplace-lists-shipped-packages/specs/dossiq-store-surface/spec.md
 */
class StorePackageControllerTest extends TestCase {

	private CaseTypePackageChoice&MockObject $choice;

	private StorePackageController $controller;

	/**
	 * Build the controller over a mocked choice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->choice = $this->createMock(CaseTypePackageChoice::class);
		$this->controller = new StorePackageController($this->createMock(IRequest::class), $this->choice);
	}//end setUp()

	/**
	 * The install route is administrative, the list is not.
	 *
	 * @return void
	 */
	public function testOnlyTheInstallIsAdministrative(): void {
		$install = new ReflectionMethod(StorePackageController::class, 'install');
		$index = new ReflectionMethod(StorePackageController::class, 'index');

		self::assertNotEmpty($install->getAttributes(AuthorizedAdminSetting::class));
		self::assertEmpty($install->getAttributes(NoAdminRequired::class));
		self::assertNotEmpty($index->getAttributes(NoAdminRequired::class));
	}//end testOnlyTheInstallIsAdministrative()

	/**
	 * The list is what the choice ships.
	 *
	 * @return void
	 */
	public function testTheListComesFromTheChoice(): void {
		$packages = [['id' => 'subsidie']];
		$this->choice->method('shipped')->willReturn($packages);

		self::assertSame(['packages' => $packages], $this->controller->index()->getData());
	}//end testTheListComesFromTheChoice()

	/**
	 * A malformed id is refused before the catalogue is read.
	 *
	 * @return void
	 */
	public function testAMalformedIdIsABadRequest(): void {
		$this->choice->expects(self::never())->method('install');

		self::assertSame(400, $this->controller->install(id: '../vth')->getStatus());
	}//end testAMalformedIdIsABadRequest()

	/**
	 * An automatic or unknown package cannot be installed from a card.
	 *
	 * @return void
	 */
	public function testAPackageNotOnOfferIsNotInstalled(): void {
		$this->choice->method('isOffered')->willReturn(false);
		$this->choice->expects(self::never())->method('install');

		self::assertSame(404, $this->controller->install(id: 'vth')->getStatus());
	}//end testAPackageNotOnOfferIsNotInstalled()

	/**
	 * A refused install is said to be refused.
	 *
	 * @return void
	 */
	public function testARefusalIsUnprocessable(): void {
		$this->choice->method('isOffered')->willReturn(true);
		$this->choice->method('install')->willReturn(['success' => false, 'message' => 'Refused']);

		self::assertSame(422, $this->controller->install(id: 'subsidie')->getStatus());
	}//end testARefusalIsUnprocessable()

	/**
	 * An install reports its outcome.
	 *
	 * @return void
	 */
	public function testAnInstallAnswersItsOutcome(): void {
		$this->choice->method('isOffered')->willReturn(true);
		$this->choice->method('install')->willReturn(['success' => true, 'message' => 'Done']);

		$response = $this->controller->install(id: 'subsidie');

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
	}//end testAnInstallAnswersItsOutcome()
}//end class
