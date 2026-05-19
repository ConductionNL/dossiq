<?php

/**
 * JwtValidationService Unit Tests
 *
 * Tests for the JWT validation and consumer authorisation service.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\JwtValidationService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the JwtValidationService class.
 *
 * @covers \OCA\Procest\Service\JwtValidationService
 */
class JwtValidationServiceTest extends TestCase
{

    /**
     * Test that consumerHasScope returns true when no consumerMapper is available.
     *
     * When the ConsumerMapper is not present (OpenRegister unavailable), any
     * consumer is treated as a superuser and scope checks pass unconditionally.
     *
     * @return void
     */
    public function testConsumerHasScopeReturnsTrueWhenNoConsumerMapper(): void
    {
        $service = new JwtValidationService(
            consumerMapper: null,
            authorizationService: null,
            logger: $this->createMock(LoggerInterface::class)
        );
        $request = $this->createMock(IRequest::class);
        $result  = $service->consumerHasScope(
            request: $request,
            component: 'zrc',
            scope: 'zaken.lezen'
        );
        // When no consumer mapper is available, scope check should pass (superuser).
        $this->assertTrue($result);
    }//end testConsumerHasScopeReturnsTrueWhenNoConsumerMapper()

    /**
     * Test that getConsumerAuthorisaties returns null when no consumerMapper is available.
     *
     * When the ConsumerMapper is not present, the service returns null to indicate
     * unrestricted access.
     *
     * @return void
     */
    public function testGetConsumerAuthorisatiesReturnsNullWhenNoConsumerMapper(): void
    {
        $service = new JwtValidationService(
            consumerMapper: null,
            authorizationService: null,
            logger: $this->createMock(LoggerInterface::class)
        );
        $request = $this->createMock(IRequest::class);
        $result  = $service->getConsumerAuthorisaties(
            request: $request,
            component: 'zrc'
        );
        $this->assertNull($result);
    }//end testGetConsumerAuthorisatiesReturnsNullWhenNoConsumerMapper()

    /**
     * Test that validateJwtAuth returns a 401 response when no Authorization header is present.
     *
     * @return void
     */
    public function testValidateJwtAuthReturns401WhenNoHeader(): void
    {
        $service = new JwtValidationService(
            consumerMapper: null,
            authorizationService: null,
            logger: $this->createMock(LoggerInterface::class)
        );
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Authorization')->willReturn('');

        $response = $service->validateJwtAuth(request: $request);
        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatus());
    }//end testValidateJwtAuthReturns401WhenNoHeader()
}//end class
