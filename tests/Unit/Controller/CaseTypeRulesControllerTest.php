<?php

/**
 * Unit tests for CaseTypeRulesController.
 *
 * @category Test
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTypeRulesController;
use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\CaseType\CaseTypeRuleSentences;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CaseTypeRulesController.
 *
 * @covers \OCA\Dossiq\Controller\CaseTypeRulesController
 */
class CaseTypeRulesControllerTest extends TestCase {

	/**
	 * The sentences double.
	 *
	 * @var CaseTypeRuleSentences&MockObject
	 */
	private CaseTypeRuleSentences&MockObject $sentences;

	/**
	 * The read check double.
	 *
	 * @var ReadableObjects&MockObject
	 */
	private ReadableObjects&MockObject $readable;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sentences = $this->createMock(CaseTypeRuleSentences::class);
		$this->readable  = $this->createMock(ReadableObjects::class);
	}//end setUp()

	/**
	 * The controller over the doubles.
	 *
	 * @return CaseTypeRulesController The controller.
	 */
	private function controller(): CaseTypeRulesController {
		return new CaseTypeRulesController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			sentences: $this->sentences,
			readable: $this->readable
		);
	}//end controller()

	/**
	 * A readable case type answers its rows unchanged.
	 *
	 * @return void
	 */
	public function testAReadableCaseTypeAnswersItsRows(): void {
		$rows = ['rules' => [['id' => 'r1', 'title' => 'If a is 1', 'line' => 'then add task t']]];
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->expects($this->once())->method('findsAsCaller')->with('caseType', 'ct-1')->willReturn(true);
		$this->sentences->expects($this->once())->method('rulesFor')->with('ct-1')->willReturn($rows);

		$response = $this->controller()->index(id: 'ct-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rows, $response->getData());
	}//end testAReadableCaseTypeAnswersItsRows()

	/**
	 * Without a signed-in caller nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutACallerIs401AndReadsNothing(): void {
		$this->readable->method('hasCaller')->willReturn(false);
		$this->readable->expects($this->never())->method('findsAsCaller');
		$this->sentences->expects($this->never())->method('rulesFor');

		$response = $this->controller()->index(id: 'ct-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Sign in to read this case type.'], $response->getData());
	}//end testWithoutACallerIs401AndReadsNothing()

	/**
	 * A case type the caller may not read is a 404, and its rules are never written.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIs404(): void {
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willReturn(false);
		$this->sentences->expects($this->never())->method('rulesFor');

		$response = $this->controller()->index(id: 'ct-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Case type not found'], $response->getData());
	}//end testAnUnreadableCaseTypeIs404()

	/**
	 * A failing read check is a 503, never an answer.
	 *
	 * @return void
	 */
	public function testAFailingReadCheckIs503(): void {
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willThrowException(new RuntimeException('down'));
		$this->sentences->expects($this->never())->method('rulesFor');

		$response = $this->controller()->index(id: 'ct-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'The case type could not be read. Try again later.'], $response->getData());
	}//end testAFailingReadCheckIs503()

	/**
	 * A readable case type the resolver cannot find is a 404.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutAnEffectiveRowIs404(): void {
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willReturn(true);
		$this->sentences->method('rulesFor')->willReturn(null);

		$response = $this->controller()->index(id: 'ct-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testACaseTypeWithoutAnEffectiveRowIs404()
}//end class
