<?php

/**
 * LegesCaseController Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-008
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\LegesCaseController;
use OCA\Procest\Service\LegesBerekeningService;
use OCA\Procest\Service\LegesCaseCalculationService;
use OCA\Procest\Service\LegesRestitutieService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for LegesCaseController.
 *
 * @covers \OCA\Procest\Controller\LegesCaseController
 */
class LegesCaseControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * @var LegesCaseCalculationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private LegesCaseCalculationService $calculationService;

    /**
     * @var LegesBerekeningService|\PHPUnit\Framework\MockObject\MockObject
     */
    private LegesBerekeningService $berekeningService;

    /**
     * @var LegesRestitutieService|\PHPUnit\Framework\MockObject\MockObject
     */
    private LegesRestitutieService $restitutieService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The controller under test.
     *
     * @var LegesCaseController
     */
    private LegesCaseController $controller;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request            = $this->createMock(IRequest::class);
        $this->calculationService = $this->createMock(LegesCaseCalculationService::class);
        $this->berekeningService  = $this->createMock(LegesBerekeningService::class);
        $this->restitutieService  = $this->createMock(LegesRestitutieService::class);
        $this->userSession        = $this->createMock(IUserSession::class);

        $this->controller = new LegesCaseController(
            request: $this->request,
            calculationService: $this->calculationService,
            berekeningService: $this->berekeningService,
            restitutieService: $this->restitutieService,
            userSession: $this->userSession,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Set the session to an authenticated user.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function authenticateAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end authenticateAs()

    /**
     * show() returns 401 when there is no authenticated user.
     *
     * @return void
     */
    public function testShowUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->show(caseId: 'case-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testShowUnauthenticated()

    /**
     * show() returns 403 when the user cannot access the case (IDOR guard).
     *
     * @return void
     */
    public function testShowForbiddenForOtherUsersCase(): void
    {
        $this->authenticateAs(uid: 'mallory');
        $this->berekeningService->method('userCanAccessCase')->willReturn(false);

        $response = $this->controller->show(caseId: 'case-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testShowForbiddenForOtherUsersCase()

    /**
     * show() returns 404 when no calculation exists for an accessible case.
     *
     * @return void
     */
    public function testShowNotFound(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->berekeningService->method('userCanAccessCase')->willReturn(true);
        $this->berekeningService->method('getForCase')->willReturn(null);

        $response = $this->controller->show(caseId: 'case-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowNotFound()

    /**
     * show() returns the calculation for an accessible case.
     *
     * @return void
     */
    public function testShowReturnsCalculation(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->berekeningService->method('userCanAccessCase')->willReturn(true);
        $this->berekeningService->method('getForCase')->willReturn(['id' => 'ber-1', 'bedragInclBtw' => 10000]);

        $response = $this->controller->show(caseId: 'case-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(10000, $response->getData()['bedragInclBtw']);
    }//end testShowReturnsCalculation()

    /**
     * refund() rejects a missing reason/fase with 400.
     *
     * @return void
     */
    public function testRefundRequiresReasonAndFase(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->refund(caseId: 'case-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testRefundRequiresReasonAndFase()

    /**
     * refund() is forbidden when the user cannot access the case.
     *
     * @return void
     */
    public function testRefundForbidden(): void
    {
        $this->authenticateAs(uid: 'mallory');
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null): string => ($key === 'reason' ? 'coulance' : 'aanvraag')
        );
        $this->berekeningService->method('userCanAccessCase')->willReturn(false);

        $response = $this->controller->refund(caseId: 'case-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testRefundForbidden()
}//end class
