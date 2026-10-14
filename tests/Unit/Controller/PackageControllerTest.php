<?php

/**
 * The package API answers with the installer's outcome and the right status.
 *
 * Runs against the neutral fixture package in tests/fixtures/packages, never
 * a real procedure (hydra ADR-118, testing split rule 2): the installer is
 * mocked, because what is under test is the translation from outcome to
 * HTTP, which the installer's own tests do not cover.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/besluitvorming-package/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PackageController;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PackageController.
 *
 * @covers \OCA\Dossiq\Controller\PackageController
 */
class PackageControllerTest extends TestCase {

	/**
	 * A controller over a mocked installer that answers the given outcome.
	 *
	 * @param array<string, mixed> $outcome  What install() answers.
	 * @param boolean              $loggedIn Whether a user is in the session.
	 *
	 * @return PackageController The controller.
	 */
	private function controller(array $outcome = [], bool $loggedIn = true): PackageController {
		$installer = $this->createMock(PackageInstaller::class);
		$installer->method('install')->willReturn($outcome);
		$installer->method('catalogue')->willReturn(
			[['id' => 'fixture-case-type', 'title' => 'Fixture case type', 'version' => '1.0.0', 'install' => 'on-request', 'installed' => '', 'valid' => true]]
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);

		return new PackageController($this->createMock(IRequest::class), $installer, $session);
	}//end controller()

	/**
	 * An outcome of one status.
	 *
	 * @param string $status The installer status.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private static function outcome(string $status): array {
		return ['status' => $status, 'package' => 'fixture-case-type', 'version' => '1.0.0', 'message' => 'what happened'];
	}//end outcome()

	/**
	 * Installed and current answer 200 with the outcome.
	 *
	 * @return void
	 */
	public function testInstalledAndCurrentAnswer200(): void {
		foreach ([PackageInstaller::INSTALLED, PackageInstaller::CURRENT] as $status) {
			$response = $this->controller(outcome: self::outcome(status: $status))->install(id: 'fixture-case-type');

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $status);
			$this->assertSame($status, $response->getData()['status']);
		}
	}//end testInstalledAndCurrentAnswer200()

	/**
	 * A refusal answers 422 with the installer's reason; a failed write 500.
	 *
	 * @return void
	 */
	public function testARefusalAnswers422AndAFailure500(): void {
		$refused = $this->controller(outcome: self::outcome(status: PackageInstaller::REFUSED))->install(id: 'not-shipped');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame('what happened', $refused->getData()['message']);

		$failed = $this->controller(outcome: self::outcome(status: PackageInstaller::FAILED))->install(id: 'fixture-case-type');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
	}//end testARefusalAnswers422AndAFailure500()

	/**
	 * The catalogue lists what the installer lists.
	 *
	 * @return void
	 */
	public function testTheCatalogueListsTheShippedPackages(): void {
		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('fixture-case-type', $response->getData()['results'][0]['id']);
	}//end testTheCatalogueListsTheShippedPackages()

	/**
	 * Without a user nothing is listed or installed.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingHappens(): void {
		$controller = $this->controller(outcome: self::outcome(status: PackageInstaller::INSTALLED), loggedIn: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->install(id: 'fixture-case-type')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
	}//end testWithoutAUserNothingHappens()
}//end class
