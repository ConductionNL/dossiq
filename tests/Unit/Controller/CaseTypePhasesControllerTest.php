<?php

/**
 * CaseTypePhasesController Unit Tests.
 *
 * Pins the phases returned for a case type and the 404 for an unknown one.
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

use OCA\Dossiq\Controller\CaseTypePhasesController;
use OCA\Dossiq\Service\CaseType\CaseTypePhases;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypePhasesController.
 *
 * @covers \OCA\Dossiq\Controller\CaseTypePhasesController
 */
class CaseTypePhasesControllerTest extends TestCase {

	/**
	 * The phases of a known case type are returned unchanged.
	 *
	 * @return void
	 */
	public function testReturnsThePhasesOfTheCaseType(): void {
		$phases = $this->createMock(CaseTypePhases::class);
		$phases->expects($this->once())->method('phasesFor')->with('ct-1')->willReturn([['name' => 'Intake'], ['name' => 'Besluit']]);

		$response = (new CaseTypePhasesController('dossiq', $this->createMock(IRequest::class), $phases))->index('ct-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['name' => 'Intake'], ['name' => 'Besluit']], $response->getData());
	}//end testReturnsThePhasesOfTheCaseType()

	/**
	 * An unknown case type is a 404; a known type without phases is an empty 200.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIs404ButNoPhasesIsEmpty(): void {
		$phases = $this->createMock(CaseTypePhases::class);
		$phases->method('phasesFor')->willReturnCallback(static fn (string $id): ?array => ($id === 'empty' ? [] : null));
		$controller = new CaseTypePhasesController('dossiq', $this->createMock(IRequest::class), $phases);

		$missing = $controller->index('nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Case type not found'], $missing->getData());

		$empty = $controller->index('empty');
		$this->assertSame(Http::STATUS_OK, $empty->getStatus());
		$this->assertSame([], $empty->getData());
	}//end testAnUnknownCaseTypeIs404ButNoPhasesIsEmpty()
}//end class
