<?php

/**
 * The decision's return to its dossier through OpenRegister's PATCH seam, and a failed write.
 *
 * WooDossierReturnTest runs the return against a store that only saves whole
 * objects. OpenRegister also offers patchObject(), and when it does the return
 * must use it, unscoped, writing ONLY the collection's items, so a share or a
 * note another app wrote meanwhile is not saved away. A write that fails is a
 * logged "not returned", never an exception into the publication.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooDossierReturn;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The PATCH path and the failed write.
 *
 * @covers \OCA\Dossiq\Woo\WooDossierReturn
 * @uses \OCA\Dossiq\Service\Support\UnscopedObjectReader
 */
class WooDossierReturnPatchTest extends TestCase {

	private const COLLECTION = '0c0c0c0c-0000-4000-a000-000000000001';

	/**
	 * An object service with patchObject(), recording each patch.
	 *
	 * @param \Throwable|null $failure Thrown by patchObject() when set.
	 *
	 * @return object The fake.
	 */
	private static function patchingService(?\Throwable $failure = null): object {
		return new class ($failure) {
			/** @var array<int, array<string, mixed>> */
			public array $patches = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * @param \Throwable|null $failure The failure to throw.
			 */
			public function __construct(private ?\Throwable $failure) {
			}

			/**
			 * @param string     $id            The uuid.
			 * @param int|string $register      The register.
			 * @param int|string $schema        The schema.
			 * @param bool       $_rbac         RBAC.
			 * @param bool       $_multitenancy Multitenancy.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, int|string $register, int|string $schema, bool $_rbac = true, bool $_multitenancy = true): array {
				return [
					'@self' => ['id' => $id],
					'id' => $id,
					'owner' => 'subject-ref-anna',
					'share' => ['token' => 'tok'],
					'items' => [['id' => 'item-1', 'publication' => 'pub-a'], 'junk'],
				];
			}

			/**
			 * @param string               $objectId      The uuid.
			 * @param array<string, mixed> $data          The fields to write.
			 * @param int|string           $register      The register.
			 * @param int|string           $schema        The schema.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->patches[] = compact('objectId', 'data', 'register', 'schema', '_rbac', '_multitenancy');
				return $data;
			}

			/**
			 * @param mixed ...$args Never expected.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				$this->saves[] = $args;
				return [];
			}
		};
	}//end patchingService()

	/**
	 * The return over $objectService.
	 *
	 * @param object          $objectService The object service.
	 * @param LoggerInterface $logger        The logger.
	 *
	 * @return WooDossierReturn
	 */
	private function returnOver(object $objectService, LoggerInterface $logger): WooDossierReturn {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			static fn (string $key): string => ['woo_collection_register' => 'portal', 'woo_collection_schema' => 'collection'][$key] ?? ''
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('0123456789abcdef0123456789abcdef');

		return new WooDossierReturn(settingsService: $settings, random: $random, logger: $logger);
	}//end returnOver()

	/**
	 * The case of a request started from the dossier.
	 *
	 * @return array<string, mixed>
	 */
	private static function case(): array {
		return ['id' => 'case-1', 'portalSubject' => 'subject-ref-anna', 'wooRequest' => ['collectionId' => self::COLLECTION]];
	}//end case()

	/**
	 * With patchObject() only the items are written, unscoped, under the collection's uuid.
	 *
	 * @return void
	 */
	public function testThePatchWritesOnlyTheItemsUnscoped(): void {
		$service = self::patchingService();

		$returned = $this->returnOver($service, $this->createMock(LoggerInterface::class))
			->append(case: self::case(), publicationId: 'pub-b', title: 'Het besluit');

		$this->assertTrue($returned);
		$this->assertSame([], $service->saves, 'No whole-object save when the PATCH seam exists.');
		$this->assertCount(1, $service->patches);
		$patch = $service->patches[0];
		$this->assertSame(self::COLLECTION, $patch['objectId']);
		$this->assertSame(['portal', 'collection', false, false], [$patch['register'], $patch['schema'], $patch['_rbac'], $patch['_multitenancy']]);
		$this->assertSame(['items'], array_keys($patch['data']));
		$this->assertSame(['pub-a', 'pub-b'], array_column($patch['data']['items'], 'publication'));
		$this->assertSame('01234567-89ab-4def-8123-456789abcdef', $patch['data']['items'][1]['id']);
		$this->assertSame(WooDossierReturn::ADDED_BY, $patch['data']['items'][1]['addedBy']);
		$this->assertArrayNotHasKey('attachment', $patch['data']['items'][1]);
	}//end testThePatchWritesOnlyTheItemsUnscoped()

	/**
	 * A write that fails is logged against the collection and answers false.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'WooDossierReturn: the decision could not be added to its source dossier',
			['collection' => self::COLLECTION, 'publication' => 'pub-b', 'error' => 'schema refused']
		);

		$returned = $this->returnOver(self::patchingService(failure: new RuntimeException('schema refused')), $logger)
			->append(case: self::case(), publicationId: 'pub-b', title: 'Het besluit');

		$this->assertFalse($returned);
	}//end testAFailedWriteIsLoggedNotThrown()
}//end class
