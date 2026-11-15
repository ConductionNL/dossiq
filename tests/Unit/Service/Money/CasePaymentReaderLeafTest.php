<?php

/**
 * CasePaymentReader leaf-resolution tests.
 *
 * Shillinq installed is not the same as shillinq answering: a leaf that the
 * container cannot build, or that has no `list()`, is no leaf, and the case
 * reads as stale rather than as owing nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTime;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\Money\CasePaymentReader
 * @uses \OCA\Dossiq\Service\Money\CasePaymentState
 */
class CasePaymentReaderLeafTest extends TestCase {

	/**
	 * Build a reader around an installed shillinq and the given container.
	 *
	 * @param ContainerInterface   $container The container.
	 * @param LoggerInterface|null $logger    The logger, or a silent one.
	 *
	 * @return CasePaymentReader The reader.
	 */
	private function reader(ContainerInterface $container, ?LoggerInterface $logger = null): CasePaymentReader {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('shillinq')->willReturn(true);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-18T10:00:00+02:00'));

		return new CasePaymentReader(
			appManager: $apps,
			container: $container,
			time: $time,
			states: new CasePaymentState(),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end reader()

	/**
	 * A leaf the container cannot build is logged and is no leaf.
	 *
	 * @return void
	 */
	public function testALeafTheContainerCannotBuildIsLoggedAndIsNoLeaf(): void {
		$failure = new RuntimeException('no such service');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with('OCA\\Shillinq\\Integration\\PaymentRequestLeafProvider')
			->willThrowException($failure);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: shillinq is installed but its payment leaf could not be resolved',
			['exception' => $failure, 'class' => '\\OCA\\Shillinq\\Integration\\PaymentRequestLeafProvider'],
		);

		self::assertNull($this->reader(container: $container, logger: $logger)->paymentLeaf());
	}//end testALeafTheContainerCannotBuildIsLoggedAndIsNoLeaf()

	/**
	 * A service without `list()` is not the payment leaf.
	 *
	 * @return void
	 */
	public function testAServiceWithoutListIsNoLeaf(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new stdClass());

		self::assertNull($this->reader(container: $container)->paymentLeaf());
	}//end testAServiceWithoutListIsNoLeaf()

	/**
	 * The resolved leaf is handed back as is.
	 *
	 * @return void
	 */
	public function testTheResolvedLeafIsReturned(): void {
		$leaf = new PaymentRequestLeafProvider();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($leaf);

		self::assertSame($leaf, $this->reader(container: $container)->paymentLeaf());
	}//end testTheResolvedLeafIsReturned()

	/**
	 * An installed shillinq whose leaf cannot be built reads as stale, never as nothing owed.
	 *
	 * @return void
	 */
	public function testAnUnresolvableLeafMakesTheCaseStale(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new stdClass());

		$projection = $this->reader(container: $container)->stateOf(caseId: 'case-1');

		self::assertSame(CasePaymentState::STALE, $projection['paymentState']);
		self::assertSame('2026-09-18T10:00:00+02:00', $projection['paymentStateCheckedAt']);
	}//end testAnUnresolvableLeafMakesTheCaseStale()

	/**
	 * A leaf that throws is logged with the case id and reads as stale.
	 *
	 * @return void
	 */
	public function testALeafThatThrowsIsLoggedWithTheCaseAndReadsAsStale(): void {
		$leaf = new class {
			/**
			 * A leaf call that fails.
			 *
			 * @param string $register The register slug.
			 * @param string $schema   The schema slug.
			 * @param string $objectId The object id.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function list(string $register, string $schema, string $objectId): array {
				throw new RuntimeException('shillinq said no');
			}//end list()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($leaf);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the payment state of a case could not be read from shillinq',
			$this->callback(static fn (array $context): bool => $context['caseId'] === 'case-7' && $context['exception'] instanceof RuntimeException),
		);

		$projection = $this->reader(container: $container, logger: $logger)->stateOf(caseId: 'case-7');

		self::assertSame(CasePaymentState::STALE, $projection['paymentState']);
	}//end testALeafThatThrowsIsLoggedWithTheCaseAndReadsAsStale()
}//end class
