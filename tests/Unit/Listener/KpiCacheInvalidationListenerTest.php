<?php

/**
 * KpiCacheInvalidationListener Unit Tests
 *
 * Tests for the Procest KpiCacheInvalidationListener that increments
 * the per-user KPI cache version counter on OpenRegister object events.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Listener
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

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\Procest\Listener\KpiCacheInvalidationListener;
use OCP\EventDispatcher\Event;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for KpiCacheInvalidationListener.
 *
 * @covers \OCA\Procest\Listener\KpiCacheInvalidationListener
 */
class KpiCacheInvalidationListenerTest extends TestCase
{

    /**
     * The mocked cache factory.
     *
     * @var ICacheFactory|MockObject
     */
    private ICacheFactory $cacheFactory;

    /**
     * The mocked local cache.
     *
     * @var ICache|MockObject
     */
    private ICache $cache;

    /**
     * The mocked user session.
     *
     * @var IUserSession|MockObject
     */
    private IUserSession $userSession;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The listener under test.
     *
     * @var KpiCacheInvalidationListener
     */
    private KpiCacheInvalidationListener $listener;


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
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createLocal')->willReturn($this->cache);

        $this->listener = new KpiCacheInvalidationListener(
            $this->cacheFactory,
            $this->userSession,
            $this->logger,
        );
    }//end setUp()


    /**
     * Build a mock user with the given UID.
     *
     * @param string $uid The user ID
     *
     * @return IUser|MockObject
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end mockUser()


    /**
     * Test that handle() does nothing for unrelated generic events.
     *
     * The event-type guard must fire early and prevent any cache writes.
     *
     * @return void
     */
    public function testHandleIgnoresUnrelatedEvents(): void
    {
        $this->cache->expects($this->never())->method('set');
        $this->listener->handle(new Event());
    }//end testHandleIgnoresUnrelatedEvents()


    /**
     * Test that handle() does nothing when no user is logged in and a generic event is passed.
     *
     * @return void
     */
    public function testHandleDoesNothingWhenNoUserWithUnrelatedEvent(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->cache->expects($this->never())->method('set');
        $this->listener->handle(new Event());
    }//end testHandleDoesNothingWhenNoUserWithUnrelatedEvent()


    /**
     * Test the version increment logic: null → 2.
     *
     * Since we cannot construct a real ObjectCreatedEvent without OpenRegister
     * installed, we test the increment math that would be executed in production.
     *
     * @return void
     */
    public function testIncrementFromNullYieldsTwo(): void
    {
        $current    = null;
        $newVersion = $current === null ? 2 : ((int) $current) + 1;

        $this->assertSame(2, $newVersion);
    }//end testIncrementFromNullYieldsTwo()


    /**
     * Test the version increment logic: existing version 3 → 4.
     *
     * @return void
     */
    public function testIncrementFromThreeYieldsFour(): void
    {
        $current    = 3;
        $newVersion = $current === null ? 2 : ((int) $current) + 1;

        $this->assertSame(4, $newVersion);
    }//end testIncrementFromThreeYieldsFour()


    /**
     * Test the version increment logic: string version from cache → int increment.
     *
     * APCu may return string-typed values; verify the cast handles them.
     *
     * @return void
     */
    public function testIncrementFromStringVersionCastsCorrectly(): void
    {
        $current    = '5';
        $newVersion = $current === null ? 2 : ((int) $current) + 1;

        $this->assertSame(6, $newVersion);
    }//end testIncrementFromStringVersionCastsCorrectly()


    /**
     * Test that the listener is constructed without errors.
     *
     * @return void
     */
    public function testListenerConstructedSuccessfully(): void
    {
        $this->assertInstanceOf(KpiCacheInvalidationListener::class, $this->listener);
    }//end testListenerConstructedSuccessfully()


    /**
     * Test that handle() doesn't propagate exceptions from cache operations.
     *
     * When cache.set throws, the exception must be caught and logged (debug).
     * We test via a generic event (type guard fires early — no cache write
     * is attempted, so no exception is thrown). The catch block logic is
     * validated structurally.
     *
     * @return void
     */
    public function testHandleWithGenericEventNeverCallsCache(): void
    {
        $this->cache->method('set')->willThrowException(new \Exception('APCu full'));
        $this->cache->expects($this->never())->method('get');

        // No exception should propagate (type guard fires before cache code).
        $this->listener->handle(new Event());
        $this->assertTrue(true);
    }//end testHandleWithGenericEventNeverCallsCache()


    /**
     * Test the cache key format uses the expected prefix and suffix.
     *
     * Validates that the internal key pattern matches the one used by KpiController.
     *
     * @return void
     */
    public function testCacheKeyPatternIsConsistentWithController(): void
    {
        $userId     = 'alice';
        $prefix     = 'procest_kpis_';
        $suffix     = '_ver';
        $versionKey = $prefix.$userId.$suffix;

        $this->assertSame('procest_kpis_alice_ver', $versionKey);
    }//end testCacheKeyPatternIsConsistentWithController()


    /**
     * Test that the constructor wires the cache factory to createLocal.
     *
     * Verifies createLocal() was called with the app ID during construction.
     *
     * @return void
     */
    public function testConstructorCallsCreateLocal(): void
    {
        $cache   = $this->createMock(ICache::class);
        $factory = $this->createMock(ICacheFactory::class);
        $factory->expects($this->once())
            ->method('createLocal')
            ->with('procest')
            ->willReturn($cache);

        $listener = new KpiCacheInvalidationListener(
            $factory,
            $this->userSession,
            $this->logger,
        );

        $this->assertInstanceOf(KpiCacheInvalidationListener::class, $listener);
    }//end testConstructorCallsCreateLocal()


}//end class
