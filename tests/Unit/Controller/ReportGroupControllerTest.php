<?php

/**
 * Unit tests for ReportGroupController: the endpoint reads a case's report
 * group and never places it, and refuses a missing or unreadable case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ReportGroupController;
use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\ReportGroupController
 */
class ReportGroupControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>   $params   The request parameters.
	 * @param ReportGroupingConsumer $grouping The grouping double.
	 * @param bool                   $signedIn Whether somebody is signed in.
	 *
	 * @return ReportGroupController The controller.
	 */
	private function controller(array $params, ReportGroupingConsumer $grouping, bool $signedIn = true): ReportGroupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ReportGroupController(
			appName: 'dossiq',
			request: $request,
			grouping: $grouping,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * The endpoint answers the case's current group and never places the case.
	 *
	 * @return void
	 */
	public function testItAnswersTheCurrentGroupWithoutPlacing(): void {
		$grouping = $this->createMock(ReportGroupingConsumer::class);
		$grouping->expects(self::never())->method('placeCase');
		$grouping->method('currentGroup')->with('case-1')->willReturn(
			['declared' => true, 'available' => true, 'group' => ['groupId' => 'g-7', 'count' => 200]]
		);

		$response = $this->controller(params: ['caseId' => 'case-1'], grouping: $grouping)->show();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(200, $response->getData()['group']['count']);
	}//end testItAnswersTheCurrentGroupWithoutPlacing()

	/**
	 * A case the caller cannot read is 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsNotFound(): void {
		$grouping = $this->createMock(ReportGroupingConsumer::class);
		$grouping->method('currentGroup')->willReturn(null);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(params: ['caseId' => 'case-hidden'], grouping: $grouping)->show()->getStatus());
	}//end testAnUnreadableCaseIsNotFound()

	/**
	 * A request naming no case is 400, and nobody signed in is 401, before anything is read.
	 *
	 * @return void
	 */
	public function testAMissingCaseOrUserIsRefusedBeforeAnyRead(): void {
		$grouping = $this->createMock(ReportGroupingConsumer::class);
		$grouping->expects(self::never())->method('currentGroup');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(params: [], grouping: $grouping)->show()->getStatus());
		self::assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller(params: ['caseId' => 'case-1'], grouping: $grouping, signedIn: false)->show()->getStatus()
		);
	}//end testAMissingCaseOrUserIsRefusedBeforeAnyRead()
}//end class
