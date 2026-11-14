<?php

/**
 * CaseTypeRightsController Unit Tests.
 *
 * Pins the rights rows returned for a case type and the 404 for an unknown one.
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

use OCA\Dossiq\Controller\CaseTypeRightsController;
use OCA\Dossiq\Service\CaseType\CaseTypeRightsByRole;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeRightsController.
 *
 * @covers \OCA\Dossiq\Controller\CaseTypeRightsController
 */
class CaseTypeRightsControllerTest extends TestCase {

	/**
	 * The rows of a known case type are wrapped in a rows member.
	 *
	 * @return void
	 */
	public function testWrapsTheRowsOfTheCaseType(): void {
		$rights = $this->createMock(CaseTypeRightsByRole::class);
		$rights->expects($this->once())->method('rowsFor')->with('ct-1')->willReturn([['role' => 'behandelaar', 'rights' => ['read']]]);

		$response = (new CaseTypeRightsController('dossiq', $this->createMock(IRequest::class), $rights))->index('ct-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['rows' => [['role' => 'behandelaar', 'rights' => ['read']]]], $response->getData());
	}//end testWrapsTheRowsOfTheCaseType()

	/**
	 * An unknown case type is a 404; a known type without rows is an empty list.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIs404ButNoRowsIsEmpty(): void {
		$rights = $this->createMock(CaseTypeRightsByRole::class);
		$rights->method('rowsFor')->willReturnCallback(static fn (string $id): ?array => ($id === 'empty' ? [] : null));
		$controller = new CaseTypeRightsController('dossiq', $this->createMock(IRequest::class), $rights);

		$missing = $controller->index('nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Case type not found'], $missing->getData());

		$this->assertSame(['rows' => []], $controller->index('empty')->getData());
	}//end testAnUnknownCaseTypeIs404ButNoRowsIsEmpty()
}//end class
