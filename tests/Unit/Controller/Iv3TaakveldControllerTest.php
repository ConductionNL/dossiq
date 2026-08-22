<?php

/**
 * Iv3TaakveldController Unit Tests
 *
 * Covers the one endpoint dossiq keeps from the retired IV3 surface: the
 * taakveld reference list that populates the case-type classification picker
 * (ADR-081 decision 2). Two things matter about it — that an unauthenticated
 * caller is refused, and that an ordinary authenticated user is NOT, because
 * this is a public CBS classification rather than report data, and the picker
 * has to work for every case-type editor.
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

use OCA\Dossiq\Controller\Iv3TaakveldController;
use OCA\Dossiq\Service\Iv3TaakveldList;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Iv3TaakveldController.
 *
 * @covers \OCA\Dossiq\Controller\Iv3TaakveldController
 * @uses   \OCA\Dossiq\Service\Iv3TaakveldList
 */
class Iv3TaakveldControllerTest extends TestCase {
	/**
	 * Build the controller with a session that either has a user or does not.
	 *
	 * @param bool $authenticated Whether a user is logged in.
	 *
	 * @return Iv3TaakveldController
	 */
	private function controller(bool $authenticated): Iv3TaakveldController {
		$session = $this->createMock(IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		return new Iv3TaakveldController(
			'dossiq',
			$this->createMock(IRequest::class),
			new Iv3TaakveldList(),
			$session
		);
	}//end controller()

	/**
	 * An authenticated user gets the list — including a plain user with no
	 * special group. The picker must work for anyone who can edit a case type.
	 *
	 * @return void
	 */
	public function testAuthenticatedUserGetsTheTaakveldList(): void {
		$response = $this->controller(authenticated: true)->taakvelden();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('version', $data);
		$this->assertArrayHasKey('taakvelden', $data);
		$this->assertNotEmpty($data['taakvelden'], 'an empty list would leave the picker unusable');
	}//end testAuthenticatedUserGetsTheTaakveldList()

	/**
	 * Every entry carries the code and label the picker renders. A silently
	 * reshaped list would leave the dropdown showing blanks.
	 *
	 * @return void
	 */
	public function testEveryTaakveldCarriesACodeAndLabel(): void {
		$data = $this->controller(authenticated: true)->taakvelden()->getData();

		foreach ($data['taakvelden'] as $taskField) {
			$this->assertArrayHasKey('code', $taskField);
			$this->assertArrayHasKey('label', $taskField);
			$this->assertNotSame('', trim((string)$taskField['code']));
			$this->assertNotSame('', trim((string)$taskField['label']));
		}
	}//end testEveryTaakveldCarriesACodeAndLabel()

	/**
	 * An unauthenticated caller is refused rather than served.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRefused(): void {
		$response = $this->controller(authenticated: false)->taakvelden();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayNotHasKey('taakvelden', $response->getData());
	}//end testUnauthenticatedCallerIsRefused()
}//end class
