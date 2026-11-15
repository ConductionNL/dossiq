<?php

/**
 * CaseTermsController unit tests: the refusal and failure answers of every endpoint.
 *
 * @category Tests
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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTermsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\OpenWorkloadAgeServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A signed-in caller with read and change access; each service in turn refuses or breaks.
 *
 * @covers \OCA\Dossiq\Controller\CaseTermsController
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class CaseTermsControllerFailuresTest extends TestCase {

	/**
	 * The terms service double.
	 *
	 * @var CaseTermsServiceInterface&MockObject
	 */
	private CaseTermsServiceInterface $terms;

	/**
	 * The workload service double.
	 *
	 * @var OpenWorkloadAgeServiceInterface&MockObject
	 */
	private OpenWorkloadAgeServiceInterface $workload;

	/**
	 * The guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The aanvullingsverzoek service double.
	 *
	 * @var AanvullingsverzoekServiceInterface&MockObject
	 */
	private AanvullingsverzoekServiceInterface $aanvullingen;

	/**
	 * The resolution service double.
	 *
	 * @var AanvullingsverzoekResolutionServiceInterface&MockObject
	 */
	private AanvullingsverzoekResolutionServiceInterface $resolution;

	/**
	 * Fresh doubles; sofie is signed in and may read and change every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->terms = $this->createMock(CaseTermsServiceInterface::class);
		$this->workload = $this->createMock(OpenWorkloadAgeServiceInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->aanvullingen = $this->createMock(AanvullingsverzoekServiceInterface::class);
		$this->resolution = $this->createMock(AanvullingsverzoekResolutionServiceInterface::class);
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return CaseTermsController
	 */
	private function controller(): CaseTermsController {
		return new CaseTermsController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			terms: $this->terms,
			workload: $this->workload,
			guard: $this->guard,
			userSession: $this->session,
			logger: $this->logger,
			aanvullingen: $this->aanvullingen,
			resolution: $this->resolution,
		);
	}//end controller()

	/**
	 * A refusal of the given status with an empty sentence.
	 *
	 * @param string $rule   The rule.
	 * @param int    $status The status.
	 *
	 * @return RefusedException
	 */
	private function refusal(string $rule, int $status=RefusedException::STATUS_REFUSED): RefusedException {
		return new RefusedException(rule: $rule, sentence: '', status: $status);
	}//end refusal()

	/**
	 * An anonymous caller is a 401 on a read, and the service is never asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsUnauthorised(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->terms->expects($this->never())->method('citizenTermsFor');

		$response = $this->controller()->citizen(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(
			['message' => 'Sign in to read the terms on a case.', 'error' => 'not-authenticated'],
			$response->getData()
		);
	}//end testAnAnonymousCallerIsUnauthorised()

	/**
	 * A refused overview keeps its status; a broken one is a logged 503.
	 *
	 * @return void
	 */
	public function testIndexRefusalAndFailure(): void {
		$this->terms->method('overviewFor')->willReturnCallback(
			fn (string $caseId) => throw ($caseId === 'refused' ? $this->refusal('terms-hidden', 403) : new RuntimeException('db down'))
		);
		$this->logger->expects($this->once())->method('warning')->with('CaseTerms index failed: db down');

		$refused = $this->controller()->index(caseId: 'refused');
		$broken = $this->controller()->index(caseId: 'broken');

		$this->assertSame(403, $refused->getStatus());
		$this->assertSame('terms-hidden', $refused->getData()['error']);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $broken->getStatus());
		$this->assertSame(
			['message' => 'The terms on this case could not be read.', 'error' => 'terms-unreadable'],
			$broken->getData()
		);
	}//end testIndexRefusalAndFailure()

	/**
	 * The citizen view answers the case with its terms; refused keeps its status, broken is a logged 503.
	 *
	 * @return void
	 */
	public function testCitizenViewAnswersRefusesAndFails(): void {
		$this->terms->method('citizenTermsFor')->willReturnCallback(
			fn (string $caseId) => match ($caseId) {
				'ok' => [['name' => 'Beslistermijn']],
				'refused' => throw $this->refusal('citizen-term-hidden'),
				default => throw new RuntimeException('timeout'),
			}
		);
		$this->logger->expects($this->once())->method('warning')->with('CaseTerms citizen view failed: timeout');

		$ok = $this->controller()->citizen(caseId: 'ok');
		$refused = $this->controller()->citizen(caseId: 'refused');
		$broken = $this->controller()->citizen(caseId: 'broken');

		$this->assertSame(['case' => 'ok', 'terms' => [['name' => 'Beslistermijn']]], $ok->getData());
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $broken->getStatus());
		$this->assertSame('The term on this case could not be read.', $broken->getData()['message']);
	}//end testCitizenViewAnswersRefusesAndFails()

	/**
	 * Without a body, the information request asks with the defaults, and its failure is a logged 400.
	 *
	 * @return void
	 */
	public function testAFailingInformationRequestIsABadRequest(): void {
		$this->aanvullingen->expects($this->once())->method('ask')
			->with('case-1', [], '', 14, 'sofie', '', '', '', null)
			->willThrowException(new RuntimeException('no recipient'));
		$this->logger->expects($this->once())->method('warning')
			->with('CaseTerms information request failed: no recipient');

		$response = $this->controller()->requestInformation(caseId: 'case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'The request could not be made.', 'error' => 'information-request-failed'],
			$response->getData()
		);
	}//end testAFailingInformationRequestIsABadRequest()

	/**
	 * Without change access no aanvulling is recorded.
	 *
	 * @return void
	 */
	public function testReceivingWithoutChangeAccessIsForbidden(): void {
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->resolution->expects($this->never())->method('recordAnswer');

		$response = $this->controller()->receiveInformation(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'You cannot change this case.', 'error' => 'case-change-refused'], $response->getData());
	}//end testReceivingWithoutChangeAccessIsForbidden()

	/**
	 * Without a body the answer is recorded as complete with nothing received; refused and broken answers.
	 *
	 * @return void
	 */
	public function testReceivingRecordsRefusesAndFails(): void {
		$calls = 0;
		$this->resolution->expects($this->exactly(3))->method('recordAnswer')
			->with('case-1', [], true, 'sofie')
			->willReturnCallback(function () use (&$calls) {
				$calls++;
				return match ($calls) {
					1 => ['resumed' => true],
					2 => throw $this->refusal('no-open-request', 422),
					default => throw new RuntimeException('write failed'),
				};
			});
		$this->logger->expects($this->once())->method('warning')->with('CaseTerms aanvulling failed: write failed');

		$ok = $this->controller()->receiveInformation(caseId: 'case-1');
		$refused = $this->controller()->receiveInformation(caseId: 'case-1');
		$broken = $this->controller()->receiveInformation(caseId: 'case-1');

		$this->assertSame(['resumed' => true], $ok->getData());
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('no-open-request', $refused->getData()['error']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $broken->getStatus());
		$this->assertSame(
			['message' => 'The aanvulling could not be recorded.', 'error' => 'aanvulling-failed'],
			$broken->getData()
		);
	}//end testReceivingRecordsRefusesAndFails()

	/**
	 * A refused list of requests keeps its status.
	 *
	 * @return void
	 */
	public function testARefusedRequestListKeepsItsStatus(): void {
		$this->aanvullingen->method('forCase')->willThrowException($this->refusal('case-hidden', 403));

		$response = $this->controller()->aanvullingsverzoeken(caseId: 'case-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('case-hidden', $response->getData()['error']);
	}//end testARefusedRequestListKeepsItsStatus()

	/**
	 * The workload report answers; refused keeps its status, broken is a logged 503; the legacy path is the same.
	 *
	 * @return void
	 */
	public function testWorkloadAgeAnswersRefusesAndFails(): void {
		$calls = 0;
		$this->workload->method('report')->willReturnCallback(function () use (&$calls) {
			$calls++;
			return match ($calls) {
				1 => ['buckets' => ['0-7' => 3]],
				2 => throw $this->refusal('workload-hidden', 403),
				default => throw new RuntimeException('index missing'),
			};
		});
		$this->logger->expects($this->once())->method('warning')->with('CaseTerms workload age failed: index missing');

		$ok = $this->controller()->workloadAgeLegacyPath();
		$refused = $this->controller()->workloadAge();
		$broken = $this->controller()->workloadAge();

		$this->assertSame(['buckets' => ['0-7' => 3]], $ok->getData());
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $broken->getStatus());
		$this->assertSame(
			['message' => 'The workload age could not be read.', 'error' => 'workload-age-unreadable'],
			$broken->getData()
		);
	}//end testWorkloadAgeAnswersRefusesAndFails()
}//end class
