<?php

/**
 * Golden record of booking an appointment onto a case.
 *
 * Records, per world, the status and body `create` answers with and every
 * call it makes, so a refactor cannot change who may book onto a case or what
 * booking reaches the appointment service.
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

use OCA\Dossiq\Controller\AppointmentController;
use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for AppointmentController::create.
 *
 * @covers \OCA\Dossiq\Controller\AppointmentController
 */
class AppointmentCreateGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testCreateAcrossWorlds(): void {
		$full = ['caseId' => 'case-1', 'productId' => 'p-1', 'locationId' => 'l-1', 'dateTime' => '2026-12-01T10:00', 'duration' => '45', 'citizenName' => 'Sanne', 'citizenEmail' => 's@example.org', 'citizenPhone' => '0612345678', 'notes' => 'ramp'];
		$worlds = [
			'not signed in' => [$full, false, true],
			'no case' => [['productId' => 'p-1'], true, true],
			'no write access on the case' => [$full, true, false],
			'booked with every field' => [$full, true, true],
			'booked with the defaults' => [['caseId' => 'case-1'], true, true],
		];

		$record = [];
		foreach ($worlds as $name => [$params, $signedIn, $mayWrite]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $params, signedIn: $signedIn, mayWrite: $mayWrite)->create();
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'appointment-create', value: $record);
	}//end testCreateAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param bool                 $mayWrite Whether they may change the case.
	 *
	 * @return AppointmentController
	 */
	private function controllerFor(array $params, bool $signedIn, bool $mayWrite): AppointmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($mayWrite): bool {
				$this->calls[] = 'hasCaseMutationAccess ' . $caseId;
				return $mayWrite;
			}
		);

		$service = $this->createMock(AppointmentService::class);
		$service->method('bookAppointment')->willReturnCallback(
			function (string $caseId, array $data): array {
				$this->calls[] = 'bookAppointment ' . $caseId . ' ' . json_encode($data);
				return ['id' => 'apt-1'];
			}
		);

		return new AppointmentController($request, $service, $session, $guard);
	}//end controllerFor()
}//end class
