<?php

/**
 * VaststellingService::finalize() Unit Tests.
 *
 * Pins the settlement result: the patch written onto the vaststelling, the
 * clawback opened only for an overpayment with a known uitvoering, the refusal
 * for an unknown vaststelling, and a failed save logged and refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
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

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TerugvorderingService;
use OCA\Dossiq\Service\Subsidie\VaststellingService;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An in-memory ObjectService that can be told to fail its saves.
 */
class FinalizeFakeObjectService {
	/**
	 * Rows by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Whether saveObject() throws.
	 *
	 * @var boolean
	 */
	public bool $failSaves = false;

	/**
	 * @param int|string           $id       The id.
	 * @param array|null           $_extend  Unused.
	 * @param boolean              $files    Unused.
	 * @param string|int|null      $register Unused.
	 * @param string|int|null      $schema   The schema.
	 *
	 * @return FakeStoredObject The row.
	 */
	public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
		$row = ($this->store[(string)$schema][(string)$id] ?? null);
		if ($row === null) {
			throw new DoesNotExistException('Object ' . $id . ' does not exist');
		}

		return new FakeStoredObject($row);
	}//end find()

	/**
	 * @param array<string, mixed> $object   The changes.
	 * @param array|null           $extend   Unused.
	 * @param string|int|null      $register Unused.
	 * @param string|int|null      $schema   The schema.
	 * @param string|null          $uuid     The id.
	 *
	 * @return FakeStoredObject The saved row.
	 */
	public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): FakeStoredObject {
		if ($this->failSaves === true) {
			throw new RuntimeException('disk full');
		}

		$schema = (string)$schema;
		$uuid = ($uuid ?? ('generated-' . count($this->store[$schema] ?? [])));
		$merged = array_merge(($this->store[$schema][$uuid] ?? []), $object, ['id' => $uuid]);
		$this->store[$schema][$uuid] = $merged;

		return new FakeStoredObject($merged);
	}//end saveObject()
}//end class

/**
 * Tests for VaststellingService::finalize().
 */
class VaststellingFinalizeTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var FinalizeFakeObjectService
	 */
	private FinalizeFakeObjectService $objects;

	/**
	 * Error messages logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The service over the in-memory store.
	 *
	 * @return VaststellingService
	 */
	private function service(): VaststellingService {
		$this->objects = new FinalizeFakeObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'subsidie_vaststelling_schema' => 'subsidieVaststelling',
				'terugvordering_schema' => 'terugvordering',
				default => $default,
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new VaststellingService($settings, new TerugvorderingService($settings, $logger), $logger);
	}//end service()

	/**
	 * An overpayment on a vaststelling with an uitvoering opens a clawback for the difference.
	 *
	 * @return void
	 */
	public function testAnOverpaymentOpensAClawback(): void {
		$service = $this->service();
		$this->objects->store['subsidieVaststelling']['vst-1'] = ['id' => 'vst-1', 'subsidieuitvoering' => 'uit-1', 'status' => 'draft'];

		$result = $service->finalize('vst-1', 100000.0, 80000.0, 95000.0);

		self::assertSame(80000.0, $result['determination']['determinedAmount']);
		self::assertTrue($result['determination']['recoveryTrigger']);
		self::assertTrue($result['determination']['vaststellingsbeschikkingGenerated']);
		self::assertSame('determined', $result['determination']['status']);
		self::assertSame('uit-1', $result['determination']['subsidieuitvoering']);
		self::assertIsArray($result['terugvordering']);
		self::assertSame(15000.0, (float)$result['terugvordering']['amount']);
	}//end testAnOverpaymentOpensAClawback()

	/**
	 * No overpayment, or no uitvoering to claw back from, opens nothing.
	 *
	 * @return void
	 */
	public function testNoClawbackWithoutOverpaymentOrUitvoering(): void {
		$service = $this->service();
		$this->objects->store['subsidieVaststelling']['vst-1'] = ['id' => 'vst-1', 'subsidieuitvoering' => 'uit-1'];
		$this->objects->store['subsidieVaststelling']['vst-2'] = ['id' => 'vst-2'];

		$covered = $service->finalize('vst-1', 100000.0, 80000.0, 80000.0);
		self::assertFalse($covered['determination']['recoveryTrigger']);
		self::assertNull($covered['terugvordering']);

		$orphan = $service->finalize('vst-2', 100000.0, 80000.0, 95000.0);
		self::assertTrue($orphan['determination']['recoveryTrigger']);
		self::assertNull($orphan['terugvordering']);
	}//end testNoClawbackWithoutOverpaymentOrUitvoering()

	/**
	 * An unknown vaststelling is refused as not found, without an error logged.
	 *
	 * @return void
	 */
	public function testAnUnknownVaststellingIsRefused(): void {
		$service = $this->service();

		try {
			$service->finalize('missing', 1.0, 1.0, 1.0);
			self::fail('An unknown vaststelling must be refused.');
		} catch (OCSBadRequestException $e) {
			self::assertSame('Vaststelling niet gevonden', $e->getMessage());
		}

		self::assertSame([], $this->errors);
	}//end testAnUnknownVaststellingIsRefused()

	/**
	 * A failed save is logged and refused.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndRefused(): void {
		$service = $this->service();
		$this->objects->store['subsidieVaststelling']['vst-1'] = ['id' => 'vst-1'];
		$this->objects->failSaves = true;

		try {
			$service->finalize('vst-1', 1.0, 1.0, 1.0);
			self::fail('A failed save must be refused.');
		} catch (OCSBadRequestException $e) {
			self::assertSame('Kon vaststelling niet vaststellen', $e->getMessage());
		}

		self::assertSame(['Dossiq subsidie: vaststelling finalize failed: disk full'], $this->errors);
	}//end testAFailedSaveIsLoggedAndRefused()
}//end class
