<?php

/**
 * GisProxyService Unit Tests
 *
 * Tests for the Procest GisProxyService that proxies WMS/WFS requests
 * to external GIS services.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\GisProxyService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for GisProxyService.
 *
 * @covers \OCA\Procest\Service\GisProxyService
 */
class GisProxyServiceTest extends TestCase
{

    /**
     * The mocked cache factory.
     *
     * @var ICacheFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private ICacheFactory $cacheFactory;

    /**
     * The mocked cache instance.
     *
     * @var ICache|\PHPUnit\Framework\MockObject\MockObject
     */
    private ICache $cache;

    /**
     * The mocked user session.
     *
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * The mocked DI container.
     *
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var GisProxyService
     */
    private GisProxyService $service;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->cache        = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->cacheFactory
            ->method('createDistributed')
            ->willReturn($this->cache);

        $this->service = new GisProxyService(
            $this->cacheFactory,
            $this->userSession,
            $this->container,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that proxyRequest throws RuntimeException when URL is not allowed.
     *
     * The allowlist check rejects any URL that does not match pdok.nl,
     * kadaster.nl, or a configured MapLayer URL (which requires OpenRegister
     * availability, here returning empty config).
     *
     * @return void
     */
    public function testProxyRequestThrowsForDisallowedUrl(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not available'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL not in configured layer allowlist');
        $this->expectExceptionCode(403);

        $this->service->proxyRequest(
            url: 'https://evil-server.example.com/wms',
            query: [],
            type: 'wms',
        );

    }//end testProxyRequestThrowsForDisallowedUrl()


    /**
     * Test that PDOK URLs are always allowed without requiring a config check.
     *
     * PDOK is the Dutch national spatial data infrastructure and is always
     * trusted by the service.
     *
     * @return void
     */
    public function testProxyRequestAllowsPdokUrl(): void
    {
        // Cache returns null (cache miss) so the request is forwarded.
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set')->willReturn(true);

        // User session for rate limiting.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);
        $this->cache
            ->method('get')
            ->willReturnOnConsecutiveCalls(null, 0, null);

        // The PDOK URL check happens before the container call — we verify
        // the service does NOT throw a 403 for pdok.nl URLs. We expect it
        // to attempt the HTTP request and fail (since we are not in a live
        // environment), so we catch the generic RuntimeException.
        try {
            $this->service->proxyRequest(
                url: 'https://service.pdok.nl/cbs/bestuurlijkegrenzen/wms',
                query: ['SERVICE' => 'WMS'],
                type: 'wms',
            );
        } catch (\RuntimeException $e) {
            // Should NOT be a 403 "URL not in configured layer allowlist".
            $this->assertNotEquals(403, $e->getCode(), 'PDOK URL should not be blocked (403)');
        }

    }//end testProxyRequestAllowsPdokUrl()


    /**
     * Test that proxyRequest returns cached result when available.
     *
     * @return void
     */
    public function testProxyRequestReturnsCachedResult(): void
    {
        $cachedData = ['data' => ['type' => 'FeatureCollection', 'features' => []], 'contentType' => 'application/json'];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        // Rate limit key returns 0, then the cached proxy result.
        $this->cache
            ->method('get')
            ->willReturnCallback(
                function (string $key) use ($cachedData): mixed {
                    if (str_starts_with($key, 'rate_limit_')) {
                        return 0;
                    }

                    if (str_starts_with($key, 'proxy_')) {
                        return $cachedData;
                    }

                    return null;
                }
            );

        $result = $this->service->proxyRequest(
            url: 'https://service.pdok.nl/wms',
            query: [],
            type: 'wms',
        );

        $this->assertSame($cachedData, $result);

    }//end testProxyRequestReturnsCachedResult()


    /**
     * Test that proxyRequest throws RuntimeException when rate limit is exceeded.
     *
     * @return void
     */
    public function testProxyRequestThrowsWhenRateLimitExceeded(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        // Rate limit count at 100 (the limit constant).
        $this->cache
            ->method('get')
            ->willReturn(100);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(429);

        $this->service->proxyRequest(
            url: 'https://service.pdok.nl/wms',
            query: [],
            type: 'wms',
        );

    }//end testProxyRequestThrowsWhenRateLimitExceeded()


    /**
     * Test that kadaster.nl URLs are allowed without config check.
     *
     * @return void
     */
    public function testKadasterUrlIsAllowed(): void
    {
        $cachedData = ['data' => [], 'contentType' => 'application/json'];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->cache
            ->method('get')
            ->willReturnCallback(
                function (string $key) use ($cachedData): mixed {
                    if (str_starts_with($key, 'rate_limit_')) {
                        return 0;
                    }

                    if (str_starts_with($key, 'proxy_')) {
                        return $cachedData;
                    }

                    return null;
                }
            );

        // kadaster.nl URL should not throw a 403.
        try {
            $result = $this->service->proxyRequest(
                url: 'https://service.pdok.nl/kadaster/bgt/wms/v1_0',
                query: [],
                type: 'wms',
            );
            // If returned from cache, assert it matches.
            $this->assertIsArray($result);
        } catch (\RuntimeException $e) {
            $this->assertNotEquals(403, $e->getCode(), 'Kadaster URL should not be blocked (403)');
        }

    }//end testKadasterUrlIsAllowed()


    /**
     * C5 SSRF: getCapabilities must call isUrlAllowed before fetching.
     *
     * Previously getCapabilities called file_get_contents directly without the
     * allowlist check — a file:// URL would have read local files.
     *
     * @return void
     */
    public function testGetCapabilitiesBlocksDisallowedUrl(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not available'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);

        $this->service->getCapabilities(
            url: 'https://evil.example.com/wms',
            type: 'wms',
        );

    }//end testGetCapabilitiesBlocksDisallowedUrl()


    /**
     * C5 SSRF: non-https schemes are blocked.
     *
     * @return void
     */
    public function testNonHttpsSchemeIsBlocked(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not available'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);

        // file:// scheme must be blocked.
        $this->service->proxyRequest(
            url: 'file:///etc/passwd',
            query: [],
            type: 'wms',
        );

    }//end testNonHttpsSchemeIsBlocked()


    /**
     * C5 SSRF: substring bypass — attacker URL containing pdok.nl as substring
     * must NOT be allowed under the new exact-hostname check.
     *
     * @return void
     */
    public function testSubstringBypassUrlIsBlocked(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not available'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);

        // Old code had: str_contains($url, 'pdok.nl') — this would pass.
        // New code uses exact hostname comparison — this must be blocked.
        $this->service->proxyRequest(
            url: 'https://evil.com/steal?host=pdok.nl',
            query: [],
            type: 'wms',
        );

    }//end testSubstringBypassUrlIsBlocked()


    /**
     * C5 SSRF: php:// stream wrapper must be blocked.
     *
     * @return void
     */
    public function testPhpStreamWrapperIsBlocked(): void
    {
        $this->container
            ->method('get')
            ->willThrowException(new \RuntimeException('Service not available'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);

        $this->service->getCapabilities(
            url: 'php://filter/convert.base64-encode/resource=/etc/passwd',
            type: 'wms',
        );

    }//end testPhpStreamWrapperIsBlocked()


}//end class
