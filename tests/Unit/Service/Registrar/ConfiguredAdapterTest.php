<?php

/**
 * Unit test for ConfiguredAdapter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registrar;

use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use OCA\Dossiq\Service\Registrar\ConfiguredAdapter;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A configured adapter class that implements the seam is built; one that cannot be built yields the mock.
 *
 * @covers \OCA\Dossiq\Service\Registrar\ConfiguredAdapter
 * @uses \OCA\Dossiq\Service\Registrar\AdapterBinding
 * @uses \OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter
 */
class ConfiguredAdapterTest extends TestCase {

	/**
	 * Resolve the Berichtenbox seam with the named class answering as given.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param callable        $named  Produces the named adapter, or throws.
	 * @param MockAdapter     $mock   The built-in mock.
	 * @param IL10N|null      $l10n   The translator, when the test expects one to be asked.
	 *
	 * @return object The bound adapter.
	 */
	private function resolve(LoggerInterface $logger, callable $named, MockAdapter $mock, ?IL10N $l10n = null): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('dossiq', 'some_adapter_key', '')
			->willReturn('  ' . IntegriqAdapter::class . ' ');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($appConfig, $logger, $named, $mock, $l10n): object {
				return match ($id) {
					IAppConfig::class => $appConfig,
					LoggerInterface::class => $logger,
					IL10N::class => $l10n,
					IntegriqAdapter::class => $named(),
					MockAdapter::class => $mock,
				};
			}
		);

		return ConfiguredAdapter::resolve(
			container: $container,
			configKey: 'some_adapter_key',
			interface: BerichtenboxAdapterInterface::class,
			mockClass: MockAdapter::class,
			fallbackReason: 'no adapter configured',
		);
	}//end resolve()

	/**
	 * A named class that serves the seam and builds is the bound adapter, without a log line.
	 *
	 * @return void
	 */
	public function testANamedAdapterThatBuildsIsBound(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');
		$real = $this->createMock(IntegriqAdapter::class);

		$adapter = $this->resolve(
			logger: $logger,
			named: static fn (): object => $real,
			mock: new MockAdapter(logger: $logger)
		);

		$this->assertSame($real, $adapter);
	}//end testANamedAdapterThatBuildsIsBound()

	/**
	 * A named class whose construction throws yields the mock and an error naming the key.
	 *
	 * @return void
	 */
	public function testANamedAdapterThatCannotBeBuiltFallsBackToTheMock(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('error')->with(
			'translated failure',
			['app' => 'dossiq', 'exception' => 'no database']
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')
			->with($this->stringStartsWith('The adapter class named in %1$s could not be built.'), ['some_adapter_key'])
			->willReturn('translated failure');
		$mock = new MockAdapter(logger: $logger);

		$adapter = $this->resolve(
			logger: $logger,
			named: static function (): object {
				throw new RuntimeException('no database');
			},
			mock: $mock,
			l10n: $l10n
		);

		$this->assertSame($mock, $adapter);
	}//end testANamedAdapterThatCannotBeBuiltFallsBackToTheMock()
}//end class
