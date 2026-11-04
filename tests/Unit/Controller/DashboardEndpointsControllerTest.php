<?php

/**
 * Tests for the dashboard endpoints of the lists and dashboards boards: the
 * terms that end first, the complaint patterns and the queue source status.
 * Each refuses before it reads.
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

use OCA\Dossiq\Controller\ComplaintPatternsController;
use OCA\Dossiq\Controller\DeadlineEndingFirstController;
use OCA\Dossiq\Controller\WorkQueueSourcesController;
use OCA\Dossiq\Service\Complaint\ComplaintPatterns;
use OCA\Dossiq\Service\Complaint\ComplaintPatternsPdf;
use OCA\Dossiq\Service\Queue\QueueSourceStatus;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCA\Dossiq\Service\Termijn\TermsEndingFirst;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DeadlineEndingFirstController, ComplaintPatternsController and WorkQueueSourcesController.
 */
class DashboardEndpointsControllerTest extends TestCase {

	/**
	 * Ending first answers the list for a caller in the audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
	 */
	public function testEndingFirstAnswersTheTerms(): void {
		$terms = $this->createMock(originalClassName: TermsEndingFirst::class);
		$terms->expects($this->once())->method('list')->with(7)->willReturn(['available' => true, 'terms' => []]);

		$response = $this->endingFirst(terms: $terms, inAudience: true)->index(limit: 7);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertTrue(condition: $response->getData()['available']);
	}//end testEndingFirstAnswersTheTerms()

	/**
	 * Ending first refuses a caller outside the audience and reads nothing.
	 *
	 * @return void
	 */
	public function testEndingFirstRefusesOutsideTheAudience(): void {
		$terms = $this->createMock(originalClassName: TermsEndingFirst::class);
		$terms->expects($this->never())->method('list');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->endingFirst(terms: $terms, inAudience: false)->index()->getStatus());
	}//end testEndingFirstRefusesOutsideTheAudience()

	/**
	 * A read failure is a 500 with a fixed message.
	 *
	 * @return void
	 */
	public function testEndingFirstMasksAReadFailure(): void {
		$terms = $this->createMock(originalClassName: TermsEndingFirst::class);
		$terms->method('list')->willThrowException(new RuntimeException('register offline'));

		$response = $this->endingFirst(terms: $terms, inAudience: true)->index();

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: 'Internal error', actual: $response->getData()['message']);
	}//end testEndingFirstMasksAReadFailure()

	/**
	 * The patterns refuse a caller outside the audience and read nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function testPatternsRefuseOutsideTheAudience(): void {
		$patterns = $this->createMock(originalClassName: ComplaintPatterns::class);
		$patterns->expects($this->never())->method('forQuarter');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->patterns(patterns: $patterns, inAudience: false)->index()->getStatus());
	}//end testPatternsRefuseOutsideTheAudience()

	/**
	 * A quarter that does not read YYYY-Qn is a 400, before anything is read.
	 *
	 * @return void
	 */
	public function testPatternsRefuseAMalformedQuarter(): void {
		$patterns = $this->createMock(originalClassName: ComplaintPatterns::class);
		$patterns->expects($this->never())->method('forQuarter');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $this->patterns(patterns: $patterns, inAudience: true)->index(quarter: '2026-5')->getStatus()
		);
	}//end testPatternsRefuseAMalformedQuarter()

	/**
	 * The named quarter is read and answered as JSON.
	 *
	 * @return void
	 */
	public function testPatternsAnswerTheNamedQuarter(): void {
		$patterns = $this->createMock(originalClassName: ComplaintPatterns::class);
		$patterns->expects($this->once())->method('forQuarter')
			->with($this->callback(callback: static fn ($period): bool => $period->year === 2026 && $period->quarter === 3))
			->willReturn(['available' => true, 'quarterLabel' => 'Third quarter 2026']);

		$response = $this->patterns(patterns: $patterns, inAudience: true)->index(quarter: '2026-Q3');

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$this->assertSame(expected: 'Third quarter 2026', actual: $response->getData()['quarterLabel']);
	}//end testPatternsAnswerTheNamedQuarter()

	/**
	 * `format=pdf` answers the report as a PDF download.
	 *
	 * @return void
	 */
	public function testPatternsAnswerAPdfDownload(): void {
		$patterns = $this->createMock(originalClassName: ComplaintPatterns::class);
		$patterns->method('forQuarter')->willReturn(['available' => true]);
		$pdf = $this->createMock(originalClassName: ComplaintPatternsPdf::class);
		$pdf->expects($this->once())->method('render')->willReturn('%PDF-1.4');

		$response = $this->patterns(patterns: $patterns, inAudience: true, pdf: $pdf)->index(quarter: '2026-Q3', format: 'pdf');

		$this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
		$this->assertSame(expected: 'application/pdf', actual: $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString(needle: 'complaint-patterns-2026-Q3.pdf', haystack: $response->getHeaders()['Content-Disposition']);
	}//end testPatternsAnswerAPdfDownload()

	/**
	 * The source status reads the signed-in reader's own queue.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function testSourcesReadTheReadersOwnQueue(): void {
		$status = $this->createMock(originalClassName: QueueSourceStatus::class);
		$status->expects($this->once())->method('forReader')->with('anouk')->willReturn([
			'available' => true,
			'unavailable' => [],
			'unavailableText' => '',
		]);

		$response = new WorkQueueSourcesController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(uid: 'anouk'),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			status: $status
		);

		$this->assertSame(expected: '', actual: $response->index()->getData()['unavailableText']);
	}//end testSourcesReadTheReadersOwnQueue()

	/**
	 * The source status refuses an anonymous caller and reads nothing.
	 *
	 * @return void
	 */
	public function testSourcesRefuseAnAnonymousCaller(): void {
		$status = $this->createMock(originalClassName: QueueSourceStatus::class);
		$status->expects($this->never())->method('forReader');

		$controller = new WorkQueueSourcesController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(uid: null),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			status: $status
		);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->index()->getStatus());
	}//end testSourcesRefuseAnAnonymousCaller()

	/**
	 * The ending-first controller.
	 *
	 * @param TermsEndingFirst $terms      The service.
	 * @param bool             $inAudience Whether the caller is in the reporting audience.
	 *
	 * @return DeadlineEndingFirstController
	 */
	private function endingFirst(TermsEndingFirst $terms, bool $inAudience): DeadlineEndingFirstController {
		return new DeadlineEndingFirstController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(uid: 'ctrl'),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			audience: $this->audience(inAudience: $inAudience),
			terms: $terms
		);
	}//end endingFirst()

	/**
	 * The patterns controller.
	 *
	 * @param ComplaintPatterns         $patterns   The service.
	 * @param bool                      $inAudience Whether the caller is in the reporting audience.
	 * @param ComplaintPatternsPdf|null $pdf        The PDF writer.
	 *
	 * @return ComplaintPatternsController
	 */
	private function patterns(ComplaintPatterns $patterns, bool $inAudience, ?ComplaintPatternsPdf $pdf = null): ComplaintPatternsController {
		return new ComplaintPatternsController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(uid: 'ctrl'),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			audience: $this->audience(inAudience: $inAudience),
			patterns: $patterns,
			pdf: ($pdf ?? $this->createMock(originalClassName: ComplaintPatternsPdf::class))
		);
	}//end patterns()

	/**
	 * A session for a user, or an anonymous one.
	 *
	 * @param string|null $uid The user id, or null.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The reporting audience.
	 *
	 * @param bool $inAudience Its answer.
	 *
	 * @return ReportingAudience
	 */
	private function audience(bool $inAudience): ReportingAudience {
		$audience = $this->createMock(originalClassName: ReportingAudience::class);
		$audience->method('isInAudience')->willReturn($inAudience);

		return $audience;
	}//end audience()
}//end class
