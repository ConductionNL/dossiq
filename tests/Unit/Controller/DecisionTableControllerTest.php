<?php

/**
 * DecisionTableController Unit Tests
 *
 * CRUD auth matrix (401/403/200/201) and evaluate() success + error-code to
 * HTTP-status mapping.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DecisionTableController;
use OCA\Procest\Service\Dmn\DecisionEngine;
use OCA\Procest\Service\Dmn\DecisionEvaluationException;
use OCA\Procest\Service\Dmn\DecisionTableService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Controller\DecisionTableController
 *
 * @uses \OCA\Procest\Service\Dmn\DecisionEvaluationException
 */
class DecisionTableControllerTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * @var DecisionTableService&MockObject
     */
    private DecisionTableService $tableService;

    /**
     * @var DecisionEngine&MockObject
     */
    private DecisionEngine $engine;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request      = $this->createMock(IRequest::class);
        $this->tableService = $this->createMock(DecisionTableService::class);
        $this->engine        = $this->createMock(DecisionEngine::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
    }//end setUp()

    /**
     * @return DecisionTableController
     */
    private function controller(): DecisionTableController
    {
        return new DecisionTableController(
            $this->request,
            $this->tableService,
            $this->engine,
            $this->userSession,
            $this->groupManager,
        );
    }//end controller()

    /**
     * @param string $uid The user id.
     *
     * @return void
     */
    private function loginAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end loginAs()

    // ------------------------------------------------------------------
    // Auth matrix
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testIndexReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller()->index();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testIndexReturns401WhenUnauthenticated()

    /**
     * @return void
     */
    public function testIndexReturns200ForAuthenticatedNonAdmin(): void
    {
        $this->loginAs('bob');
        $this->tableService->method('listTables')->willReturn([['name' => 'x']]);

        $response = $this->controller()->index();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(['results' => [['name' => 'x']]], $response->getData());
    }//end testIndexReturns200ForAuthenticatedNonAdmin()

    /**
     * @return void
     */
    public function testCreateReturns403ForNonAdmin(): void
    {
        $this->loginAs('bob');
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);

        $response = $this->controller()->create();

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCreateReturns403ForNonAdmin()

    /**
     * @return void
     */
    public function testCreateReturns201ForAdmin(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
        $this->request->method('getParams')->willReturn(['name' => 'Eligibility', 'key' => 'eligibility']);
        $this->tableService->method('createTable')->willReturn(['id' => '1', 'name' => 'Eligibility']);

        $response = $this->controller()->create();

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
    }//end testCreateReturns201ForAdmin()

    /**
     * @return void
     */
    public function testCreateReturns400OnValidationFailure(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);
        $this->tableService->method('createTable')->willThrowException(new OCSBadRequestException('Decision table name is required'));

        $response = $this->controller()->create();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testCreateReturns400OnValidationFailure()

    /**
     * @return void
     */
    public function testDestroyReturns403ForNonAdmin(): void
    {
        $this->loginAs('bob');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $response = $this->controller()->destroy('id-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testDestroyReturns403ForNonAdmin()

    /**
     * @return void
     */
    public function testDestroyReturns200ForAdmin(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->willReturn(true);

        $response = $this->controller()->destroy('id-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(['success' => true], $response->getData());
    }//end testDestroyReturns200ForAdmin()

    // ------------------------------------------------------------------
    // evaluate()
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testEvaluateReturns404WhenTableNotFound(): void
    {
        $this->loginAs('bob');
        $this->tableService->method('getTable')->willReturn(null);

        $response = $this->controller()->evaluate('missing-id');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testEvaluateReturns404WhenTableNotFound()

    /**
     * @return void
     */
    public function testEvaluateReturns200OnSuccess(): void
    {
        $this->loginAs('bob');
        $this->request->method('getParams')->willReturn(['income' => 10000]);
        $this->tableService->method('getTable')->willReturn(['hitPolicy' => 'UNIQUE']);
        $this->engine->method('evaluate')->willReturn(['outputs' => ['eligible' => true], 'matchedRuleIds' => ['r1'], 'hitPolicy' => 'UNIQUE']);

        $response = $this->controller()->evaluate('table-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(['eligible' => true], $response->getData()['outputs']);
    }//end testEvaluateReturns200OnSuccess()

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public static function errorCodeStatusProvider(): array
    {
        return [
            ['unknown_input', Http::STATUS_BAD_REQUEST],
            ['missing_input', Http::STATUS_BAD_REQUEST],
            ['type_mismatch', Http::STATUS_BAD_REQUEST],
            ['invalid_expression', Http::STATUS_BAD_REQUEST],
            ['hit_policy_not_implemented', Http::STATUS_BAD_REQUEST],
            ['no_rule_matched', Http::STATUS_UNPROCESSABLE_ENTITY],
            ['hit_policy_violation', Http::STATUS_UNPROCESSABLE_ENTITY],
        ];
    }//end errorCodeStatusProvider()

    /**
     * @dataProvider errorCodeStatusProvider
     *
     * @param string $errorCode      The DecisionEvaluationException error code.
     * @param int    $expectedStatus The expected HTTP status.
     *
     * @return void
     */
    public function testEvaluateMapsErrorCodeToHttpStatus(string $errorCode, int $expectedStatus): void
    {
        $this->loginAs('bob');
        $this->request->method('getParams')->willReturn(['income' => 10000]);
        $this->tableService->method('getTable')->willReturn(['hitPolicy' => 'UNIQUE']);
        $this->engine->method('evaluate')->willThrowException(new DecisionEvaluationException($errorCode));

        $response = $this->controller()->evaluate('table-1');

        self::assertSame($expectedStatus, $response->getStatus());
        self::assertSame($errorCode, $response->getData()['error']);
    }//end testEvaluateMapsErrorCodeToHttpStatus()
}//end class
