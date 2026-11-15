<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The calls PlatformDataSubjectRights makes, and what it answers when a service is missing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Gdpr;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Gdpr\PlatformDataSubjectRights;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Availability, preview loading, exports, and an unresolvable service.
 *
 * @covers \OCA\Dossiq\Service\Gdpr\PlatformDataSubjectRights
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class PlatformDataSubjectRightsCallsTest extends TestCase {

	private const PREVIEW_SERVICE = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService';
	private const PREVIEW_STORE = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore';
	private const EXPORT_SERVICE = 'OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService';

	/**
	 * A container answering the given services; get() throws for others.
	 *
	 * @param array<string, mixed> $services The services by id.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => array_key_exists($id, $services));
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('not registered: '.$id);
				}

				return $services[$id];
			}
		);
		return $container;
	}//end container()

	/**
	 * An entity whose jsonSerialize answers the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return object
	 */
	private static function entity(array $row): object {
		return new class($row) {
			public function __construct(private readonly array $row) {
			}

			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end entity()

	/**
	 * An export service recording its calls.
	 *
	 * @param object|null $loaded What load() answers.
	 *
	 * @return object
	 */
	private static function exportService(?object $loaded): object {
		return new class($loaded) {
			public array $calls = [];

			public function __construct(private readonly ?object $loaded) {
			}

			public function request(string $subject, ?string $type = null, ?string $requestId = null): object {
				$this->calls[] = ['request', $subject, $type, $requestId];
				return PlatformDataSubjectRightsCallsTest::row(['uuid' => 'e1', 'downloadable' => false]);
			}

			public function load(string $uuid): ?object {
				$this->calls[] = ['load', $uuid];
				return $this->loaded;
			}
		};
	}//end exportService()

	/**
	 * Public alias of entity() for the anonymous doubles.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return object
	 */
	public static function row(array $row): object {
		return self::entity(row: $row);
	}//end row()

	/**
	 * Available only when both the preview service and the store are registered.
	 *
	 * @return void
	 */
	public function testIsAvailableNeedsTheServiceAndTheStore(): void {
		$both = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_SERVICE => new \stdClass(), self::PREVIEW_STORE => new \stdClass()]), logger: new NullLogger());
		$storeOnly = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_STORE => new \stdClass()]), logger: new NullLogger());
		$serviceOnly = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_SERVICE => new \stdClass()]), logger: new NullLogger());

		$this->assertTrue($both->isAvailable());
		$this->assertFalse($storeOnly->isAvailable());
		$this->assertFalse($serviceOnly->isAvailable());
	}//end testIsAvailableNeedsTheServiceAndTheStore()

	/**
	 * preview() loads the stored preview by its uuid.
	 *
	 * @return void
	 */
	public function testPreviewLoadsTheStoredPreview(): void {
		$store = new class {
			public array $loaded = [];

			public function load(string $uuid): object {
				$this->loaded[] = $uuid;
				return PlatformDataSubjectRightsCallsTest::row(['uuid' => $uuid, 'digest' => 'd']);
			}
		};
		$rights = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_STORE => $store]), logger: new NullLogger());

		$this->assertSame(['uuid' => 'p1', 'digest' => 'd'], $rights->preview(previewId: 'p1'));
		$this->assertSame(['p1'], $store->loaded);
	}//end testPreviewLoadsTheStoredPreview()

	/**
	 * The whole-object mode is passed through, even with surrounding spaces.
	 *
	 * @return void
	 */
	public function testTheWholeObjectModeIsKept(): void {
		$service = new class {
			public array $modes = [];

			public function preview(SubjectQuery $subject, string $eraseMode = 'pseudonymise'): array {
				$this->modes[] = [$subject->subjectId, $subject->type, $eraseMode];
				return [];
			}
		};
		$store = new class {
			public function record(array $preview, ?string $requestId = null): object {
				return PlatformDataSubjectRightsCallsTest::row(['requestId' => $requestId]);
			}
		};
		$rights = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_SERVICE => $service, self::PREVIEW_STORE => $store]), logger: new NullLogger());

		$this->assertSame(['requestId' => 'c1'], $rights->previewErasure(subject: 's1', type: 'bsn', eraseMode: ' whole-object ', requestId: 'c1'));
		$this->assertSame([['s1', 'bsn', 'whole-object']], $service->modes);
	}//end testTheWholeObjectModeIsKept()

	/**
	 * An export request passes subject, type and request id and answers the record.
	 *
	 * @return void
	 */
	public function testRequestExportPassesItsArguments(): void {
		$service = self::exportService(loaded: null);
		$rights = new PlatformDataSubjectRights(container: $this->container([self::EXPORT_SERVICE => $service]), logger: new NullLogger());

		$this->assertSame(['uuid' => 'e1', 'downloadable' => false], $rights->requestExport(subject: 's1', type: null, requestId: 'c1'));
		$this->assertSame([['request', 's1', null, 'c1']], $service->calls);
	}//end testRequestExportPassesItsArguments()

	/**
	 * A stored export is answered serialized.
	 *
	 * @return void
	 */
	public function testAStoredExportIsAnswered(): void {
		$service = self::exportService(loaded: self::entity(row: ['uuid' => 'e9', 'downloadable' => true]));
		$rights = new PlatformDataSubjectRights(container: $this->container([self::EXPORT_SERVICE => $service]), logger: new NullLogger());

		$this->assertSame(['uuid' => 'e9', 'downloadable' => true], $rights->export(exportId: 'e9'));
		$this->assertSame([['load', 'e9']], $service->calls);
	}//end testAStoredExportIsAnswered()

	/**
	 * A service the container cannot build is logged as a warning and refused as unavailable.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceIsRefusedAsUnavailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'OpenRegister data subject rights service did not resolve: '.self::EXPORT_SERVICE,
			['exception' => 'not registered: '.self::EXPORT_SERVICE]
		);
		$rights = new PlatformDataSubjectRights(container: $this->container([]), logger: $logger);

		try {
			$rights->export(exportId: 'e1');
			$this->fail('a missing service must refuse');
		} catch (RefusedException $e) {
			$this->assertSame('data-subject-rights-unavailable', $e->getRule());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testAnUnresolvableServiceIsRefusedAsUnavailable()

	/**
	 * A container answering a non-object is refused without a warning.
	 *
	 * @return void
	 */
	public function testANonObjectServiceIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$rights = new PlatformDataSubjectRights(container: $this->container([self::PREVIEW_STORE => null]), logger: $logger);

		try {
			$rights->approvePreview(previewId: 'p1');
			$this->fail('a non-object service must refuse');
		} catch (RefusedException $e) {
			$this->assertSame('data-subject-rights-unavailable', $e->getRule());
			$this->assertStringStartsWith('This OpenRegister cannot answer data subject requests yet.', $e->getSentence());
		}
	}//end testANonObjectServiceIsRefused()
}//end class
