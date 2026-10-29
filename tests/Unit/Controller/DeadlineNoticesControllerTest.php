<?php

/**
 * DeadlineNoticesController tests: the notices of default by case answer only
 * a caller in the reporting audience.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DeadlineNoticesController;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DeadlineNoticesController.
 */
class DeadlineNoticesControllerTest extends TestCase {

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Who may read a figure about every case.
	 *
	 * @var ReportingAudience&MockObject
	 */
	private ReportingAudience $audience;

	/**
	 * Set up the session and an audience that admits everyone.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->audience    = $this->createMock(originalClassName: ReportingAudience::class);
		$this->audience->method('isInAudience')->willReturn(true);
	}//end setUp()

	/**
	 * The route answers the notices by case for a caller in the audience.
	 *
	 * Contract coverage for `deadlineNotices#notices`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notices-of-default-by-case/specs/termijn-reporting/spec.md
	 */
	public function testNoticesAnswersTheNoticesByCase(): void {
		$this->signIn();
		$rows = $this->createMock(originalClassName: NoticeOfDefaultRows::class);
		$rows->expects($this->once())->method('list')->willReturn(['notices' => [['caseLabel' => '2026-4863 Kapvergunning Parkstraat']]]);

		$response = $this->controller(rows: $rows)->notices();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: '2026-4863 Kapvergunning Parkstraat', actual: $response->getData()['notices'][0]['caseLabel']);
	}//end testNoticesAnswersTheNoticesByCase()

	/**
	 * The route refuses an anonymous caller and reads nothing.
	 *
	 * @return void
	 */
	public function testNoticesRefusesAnAnonymousCaller(): void {
		$rows = $this->createMock(originalClassName: NoticeOfDefaultRows::class);
		$rows->expects($this->never())->method('list');
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller(rows: $rows)->notices()->getStatus());
	}//end testNoticesRefusesAnAnonymousCaller()

	/**
	 * The route refuses a caller outside the reporting audience and reads nothing.
	 *
	 * @return void
	 */
	public function testNoticesRefusesACallerOutsideTheAudience(): void {
		$this->signIn();
		$this->audience = $this->createMock(originalClassName: ReportingAudience::class);
		$this->audience->method('isInAudience')->willReturn(false);
		$rows = $this->createMock(originalClassName: NoticeOfDefaultRows::class);
		$rows->expects($this->never())->method('list');

		$response = $this->controller(rows: $rows)->notices();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ReportingAudience::REFUSAL, actual: $response->getData()['message']);
	}//end testNoticesRefusesACallerOutsideTheAudience()

	/**
	 * Without the notice rows the route says it is not available.
	 *
	 * @return void
	 */
	public function testNoticesIsUnavailableWithoutTheRows(): void {
		$this->signIn();

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $this->controller(rows: null)->notices()->getStatus());
	}//end testNoticesIsUnavailableWithoutTheRows()

	/**
	 * A read failure answers 500 with a fixed message, not the exception's.
	 *
	 * @return void
	 */
	public function testNoticesMasksAReadFailure(): void {
		$this->signIn();
		$rows = $this->createMock(originalClassName: NoticeOfDefaultRows::class);
		$rows->method('list')->willThrowException(new RuntimeException(message: 'register offline'));

		$response = $this->controller(rows: $rows)->notices();

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: 'Internal error', actual: $response->getData()['message']);
	}//end testNoticesMasksAReadFailure()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * The controller over the given rows.
	 *
	 * @param NoticeOfDefaultRows|null $rows The notice rows, or null when not wired.
	 *
	 * @return DeadlineNoticesController
	 */
	private function controller(?NoticeOfDefaultRows $rows): DeadlineNoticesController {
		return new DeadlineNoticesController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->userSession,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			audience: $this->audience,
			notices: $rows,
		);
	}//end controller()
}//end class
