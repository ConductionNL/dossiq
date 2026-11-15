<?php

/**
 * QueueSourceCatalogue tests: every declared source is resolved from the
 * container, and one that cannot be is named with its reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Service\Queue\QueueSourceCatalogue;
use OCA\Dossiq\Service\Queue\Source\MentionSource;
use OCA\Dossiq\Service\Queue\Source\OpenIncidentSource;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests for QueueSourceCatalogue.
 *
 * @covers \OCA\Dossiq\Service\Queue\QueueSourceCatalogue
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueSourceCatalogueTest extends TestCase {

	/**
	 * The declared list is the catalogue's constant, in its order.
	 *
	 * @return void
	 */
	public function testTheDeclaredSourcesAreTheCatalogue(): void {
		$catalogue = new QueueSourceCatalogue(container: $this->createMock(ContainerInterface::class));

		$this->assertSame(expected: QueueSourceCatalogue::SOURCES, actual: $catalogue->declared());
		$this->assertCount(expectedCount: 10, haystack: $catalogue->declared());
	}//end testTheDeclaredSourcesAreTheCatalogue()

	/**
	 * Every source the container answers is resolved, in declaration order, with nothing unavailable.
	 *
	 * @return void
	 */
	public function testEverySourceIsResolvedInOrder(): void {
		$instances = [];
		foreach (QueueSourceCatalogue::SOURCES as $class) {
			$instances[$class] = $this->createMock(QueueSource::class);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(10))->method('get')
			->willReturnCallback(static fn (string $id): QueueSource => $instances[$id]);

		$resolved = (new QueueSourceCatalogue(container: $container))->resolve();

		$this->assertSame(expected: array_values($instances), actual: $resolved['sources']);
		$this->assertSame(expected: [], actual: $resolved['unavailable']);
	}//end testEverySourceIsResolvedInOrder()

	/**
	 * A source the container cannot build is named with the error, and the others still resolve.
	 *
	 * @return void
	 */
	public function testASourceThatCannotBeBuiltIsNamedWithItsReason(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): QueueSource {
				if ($id === MentionSource::class) {
					throw new RuntimeException('Mentions are down');
				}

				return $this->createMock(QueueSource::class);
			}
		);

		$resolved = (new QueueSourceCatalogue(container: $container))->resolve();

		$this->assertCount(expectedCount: 9, haystack: $resolved['sources']);
		$this->assertSame(
			expected: [['source' => MentionSource::class, 'label' => MentionSource::class, 'reason' => 'Mentions are down']],
			actual: $resolved['unavailable']
		);
	}//end testASourceThatCannotBeBuiltIsNamedWithItsReason()

	/**
	 * Something that does not implement the queue source contract is unavailable, not a source.
	 *
	 * @return void
	 */
	public function testANonSourceIsUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === OpenIncidentSource::class) ? new stdClass() : $this->createMock(QueueSource::class)
		);

		$resolved = (new QueueSourceCatalogue(container: $container))->resolve();

		$this->assertCount(expectedCount: 9, haystack: $resolved['sources']);
		$this->assertSame(
			expected: [[
				'source' => OpenIncidentSource::class,
				'label' => OpenIncidentSource::class,
				'reason' => 'This source does not implement the queue source contract.',
			]],
			actual: $resolved['unavailable']
		);
	}//end testANonSourceIsUnavailable()
}//end class
