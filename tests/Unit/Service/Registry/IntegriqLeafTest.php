<?php

/**
 * Unit tests for IntegriqLeaf.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registry;

use OCA\Dossiq\Service\Registry\IntegriqLeaf;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Finding an integriq reader, under either app id, and naming why none answers.
 *
 * @covers \OCA\Dossiq\Service\Registry\IntegriqLeaf
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class IntegriqLeafTest extends TestCase {

	/**
	 * Build the leaf.
	 *
	 * @param array<int, string>    $installed App ids that are installed.
	 * @param array<string, object> $services  Container id to service.
	 *
	 * @return IntegriqLeaf The leaf.
	 */
	private function leaf(array $installed, array $services = []): IntegriqLeaf {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => in_array($app, $installed, true)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('no ' . $id);
				}

				return $services[$id];
			}
		);

		return new IntegriqLeaf(container: $container, appManager: $appManager);
	}//end leaf()

	/**
	 * A reader with a read() method.
	 *
	 * @return object The reader.
	 */
	private function reader(): object {
		return new class {
			/**
			 * Read.
			 *
			 * @return array<string, mixed> Nothing.
			 */
			public function read(): array {
				return [];
			}
		};
	}//end reader()

	/**
	 * Without integriq installed, under either id, the reason says so.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheReasonIsNotInstalled(): void {
		self::assertSame(
			'integriq-not-installed',
			$this->leaf(installed: [])->readerOr(relative: 'Service\Bag\BagReader', missing: 'bag-reader-missing')
		);
	}//end testWithoutIntegriqTheReasonIsNotInstalled()

	/**
	 * The reader is found under the integriq namespace.
	 *
	 * @return void
	 */
	public function testTheReaderIsFoundUnderIntegriq(): void {
		$reader = $this->reader();

		self::assertSame(
			$reader,
			$this->leaf(installed: ['integriq'], services: ['OCA\Integriq\Service\Bag\BagReader' => $reader])
				->readerOr(relative: 'Service\Bag\BagReader', missing: 'bag-reader-missing')
		);
	}//end testTheReaderIsFoundUnderIntegriq()

	/**
	 * Under its old id the app is still found, and its old namespace is tried.
	 *
	 * @return void
	 */
	public function testTheOldAppIdAndNamespaceStillAnswer(): void {
		$reader = $this->reader();

		self::assertSame(
			$reader,
			$this->leaf(installed: ['openconnector'], services: ['OCA\OpenConnector\Service\Bag\BagReader' => $reader])
				->readerOr(relative: '\Service\Bag\BagReader', missing: 'bag-reader-missing')
		);
	}//end testTheOldAppIdAndNamespaceStillAnswer()

	/**
	 * An installed integriq without the reader, or with one that cannot read, answers the caller's reason.
	 *
	 * @return void
	 */
	public function testAMissingOrUnusableReaderAnswersTheCallersReason(): void {
		self::assertSame(
			'bag-reader-missing',
			$this->leaf(installed: ['integriq'])->readerOr(relative: 'Service\Bag\BagReader', missing: 'bag-reader-missing')
		);
		self::assertSame(
			'person-reader-missing',
			$this->leaf(installed: ['integriq'], services: ['OCA\Integriq\Service\Brp\PersonReader' => new \stdClass()])
				->readerOr(relative: 'Service\Brp\PersonReader', missing: 'person-reader-missing')
		);
	}//end testAMissingOrUnusableReaderAnswersTheCallersReason()

	/**
	 * An answer's own reason is kept; a missing, blank or non-string one reads as unreachable.
	 *
	 * @return void
	 */
	public function testTheUnavailableReasonPrefersTheAnswersOwn(): void {
		self::assertSame('bag-disabled', IntegriqLeaf::unavailableReason(answer: ['reason' => 'bag-disabled']));
		self::assertSame('unreachable', IntegriqLeaf::unavailableReason(answer: []));
		self::assertSame('unreachable', IntegriqLeaf::unavailableReason(answer: ['reason' => '']));
		self::assertSame('unreachable', IntegriqLeaf::unavailableReason(answer: ['reason' => 503]));
	}//end testTheUnavailableReasonPrefersTheAnswersOwn()
}//end class
