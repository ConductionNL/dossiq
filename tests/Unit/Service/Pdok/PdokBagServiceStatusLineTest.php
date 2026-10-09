<?php

/**
 * A PDOK BAG answer without an HTTP status line is refused, not read.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pdok
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pdok;

use OCA\Dossiq\Service\Pdok\PdokBagService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the direct WFS call through a `data:` endpoint.
 *
 * A `data:` stream opens and has a body, but carries no response headers, so
 * no `HTTP/x 200` line is ever seen. The service must read that as "PDOK did
 * not answer", with status 0, and not as an empty result: an empty result is
 * cached for a day and would tell a handler the building does not exist.
 */
class PdokBagServiceStatusLineTest extends TestCase {
	/**
	 * Build the service against a configured endpoint.
	 *
	 * @param string $endpoint The value of pdok_bag_endpoint.
	 * @param ICache $cache The distributed cache the service writes to.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return PdokBagService
	 */
	private function service(string $endpoint, ICache $cache, LoggerInterface $logger): PdokBagService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($endpoint): string {
				if ($key === 'pdok_bag_endpoint') {
					return $endpoint;
				}

				return $default;
			}
		);

		return new PdokBagService(
			cacheFactory: $cacheFactory,
			appConfig: $appConfig,
			container: $this->createMock(ContainerInterface::class),
			logger: $logger,
		);
	}//end service()

	/**
	 * A body with no status line throws with status 0 and is not cached.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAStatusLineIsRefusedAndNotCached(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->never())->method('set');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq PDOK BAG call failed',
				$this->callback(
					static fn (array $context): bool => $context['status'] === 0
						&& $context['error'] === 'PDOK BAG WFS HTTP 0'
				)
			);

		$service = $this->service(
			endpoint: 'data:,{"features":[]}',
			cache: $cache,
			logger: $logger
		);

		try {
			$service->getNummeraanduiding(id: '0363200000123456');
			$this->fail('An answer without a status line was read as a result.');
		} catch (RuntimeException $e) {
			$this->assertSame('PDOK BAG WFS HTTP 0', $e->getMessage());
			$this->assertSame(0, $e->getCode());
		}
	}//end testAnAnswerWithoutAStatusLineIsRefusedAndNotCached()
}//end class
