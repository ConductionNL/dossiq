<?php

/**
 * FleetAppId — class and service lookup across a fleet app's current and legacy namespace.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Service\Queue\QueueSource;
use OCA\Dossiq\Support\FleetAppId;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The namespace half of FleetAppId: candidate class names, the first one that
 * exists, the first one the container can build, and instanceof across both.
 *
 * @covers \OCA\Dossiq\Support\FleetAppId
 */
final class FleetAppIdServiceLookupTest extends TestCase {

	/**
	 * A container answering only the ids in $services; every other id throws.
	 *
	 * @param array<string, mixed> $services Id to what get() returns.
	 * @param array<int, string>   $asked    Filled with every id asked for.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services, array &$asked = []): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, &$asked) {
				$asked[] = $id;
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * The candidates are the current namespace first, then the legacy one, with
	 * a leading backslash on the relative name dropped.
	 *
	 * @return void
	 */
	public function testClassCandidatesListCurrentThenLegacy(): void {
		self::assertSame(
			['OCA\Integriq\Service\SourceService', 'OCA\OpenConnector\Service\SourceService'],
			FleetAppId::classCandidates(canonical: 'integriq', relative: '\Service\SourceService')
		);
		self::assertSame(
			['OCA\Buildiq\Db\Project', 'OCA\OpenBuilt\Db\Project'],
			FleetAppId::classCandidates(canonical: 'buildiq', relative: 'Db\Project')
		);
		self::assertSame([], FleetAppId::classCandidates(canonical: 'unknownapp', relative: 'Service\X'));
		self::assertSame([], FleetAppId::classCandidates(canonical: 'humaniq', relative: 'Service\X'));
	}//end testClassCandidatesListCurrentThenLegacy()

	/**
	 * resolveClass answers the first candidate that exists as a class or an
	 * interface, and null when none does.
	 *
	 * @return void
	 */
	public function testResolveClassFindsAnExistingClassOrInterface(): void {
		self::assertSame(FleetAppId::class, FleetAppId::resolveClass(canonical: 'dossiq', relative: 'Support\FleetAppId'));
		self::assertSame(QueueSource::class, FleetAppId::resolveClass(canonical: 'dossiq', relative: '\Service\Queue\QueueSource'));
		self::assertNull(FleetAppId::resolveClass(canonical: 'dossiq', relative: 'Support\NoSuchClass'));
		self::assertNull(FleetAppId::resolveClass(canonical: 'unknownapp', relative: 'Support\FleetAppId'));
	}//end testResolveClassFindsAnExistingClassOrInterface()

	/**
	 * getService takes the current name when registered and does not ask for
	 * the legacy one.
	 *
	 * @return void
	 */
	public function testGetServicePrefersTheCurrentName(): void {
		$current = new stdClass();
		$asked = [];
		$container = $this->container(
			['OCA\Filinq\Service\FileService' => $current, 'OCA\DocuDesk\Service\FileService' => new stdClass()],
			$asked
		);

		self::assertSame($current, FleetAppId::getService(container: $container, canonical: 'filinq', relative: 'Service\FileService'));
		self::assertSame(['OCA\Filinq\Service\FileService'], $asked);
	}//end testGetServicePrefersTheCurrentName()

	/**
	 * When the current name is not registered, or is not an object, the legacy
	 * name is tried.
	 *
	 * @return void
	 */
	public function testGetServiceFallsBackToTheLegacyName(): void {
		$legacy = new stdClass();
		$throwing = $this->container(['OCA\DocuDesk\Service\FileService' => $legacy]);
		self::assertSame($legacy, FleetAppId::getService(container: $throwing, canonical: 'filinq', relative: 'Service\FileService'));

		$asked = [];
		$notAnObject = $this->container(
			['OCA\Filinq\Service\FileService' => 'a string', 'OCA\DocuDesk\Service\FileService' => $legacy],
			$asked
		);
		self::assertSame($legacy, FleetAppId::getService(container: $notAnObject, canonical: 'filinq', relative: 'Service\FileService'));
		self::assertSame(['OCA\Filinq\Service\FileService', 'OCA\DocuDesk\Service\FileService'], $asked);
	}//end testGetServiceFallsBackToTheLegacyName()

	/**
	 * Nothing registered, or an app without namespaces, is no service.
	 *
	 * @return void
	 */
	public function testGetServiceAnswersNullWhenNothingResolves(): void {
		self::assertNull(FleetAppId::getService(container: $this->container([]), canonical: 'keepiq', relative: 'Service\X'));

		$asked = [];
		self::assertNull(FleetAppId::getService(container: $this->container([], $asked), canonical: 'unknownapp', relative: 'Service\X'));
		self::assertSame([], $asked);
	}//end testGetServiceAnswersNullWhenNothingResolves()

	/**
	 * isInstanceOf matches an instance of either candidate, and nothing else.
	 *
	 * @return void
	 */
	public function testIsInstanceOfMatchesEitherNamespace(): void {
		$source = $this->createMock(QueueSource::class);

		self::assertTrue(FleetAppId::isInstanceOf(value: $source, canonical: 'dossiq', relative: 'Service\Queue\QueueSource'));
		self::assertFalse(FleetAppId::isInstanceOf(value: new stdClass(), canonical: 'dossiq', relative: 'Service\Queue\QueueSource'));
		self::assertFalse(FleetAppId::isInstanceOf(value: 'OCA\Dossiq\Service\Queue\QueueSource', canonical: 'dossiq', relative: 'Service\Queue\QueueSource'));
		self::assertFalse(FleetAppId::isInstanceOf(value: $source, canonical: 'unknownapp', relative: 'Service\Queue\QueueSource'));
	}//end testIsInstanceOfMatchesEitherNamespace()
}//end class
