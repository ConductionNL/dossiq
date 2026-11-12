<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What TerugvorderingService stores for a clawback case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TerugvorderingService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Opening a clawback case.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\TerugvorderingService
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
class TerugvorderingPersistenceTest extends TestCase {

	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return TerugvorderingService
	 */
	private function service(?LoggerInterface $logger = null): TerugvorderingService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'terugvordering_schema' => 'terugvordering'][$key] ?? $default)
		);
		return new TerugvorderingService(settingsService: $settings, logger: ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * The case is a draft under AWB 4:57 with both terms from the publication date and a rounded amount.
	 *
	 * @return void
	 */
	public function testCreateClawbackCaseStoresADraft(): void {
		$stored = $this->service()->createClawbackCase(uitvoeringId: 'u-1', amount: 1234.567, publication: new DateTimeImmutable('2026-03-02'));

		$this->assertSame([
			'subsidieuitvoering' => 'u-1',
			'amount' => 1234.57,
			'legalBasis' => 'AWB 4:57',
			'objectionPeriodEnd' => '2026-04-13',
			'paymentTermEnd' => '2026-03-30',
			'paidAmount' => 0,
			'managerGoedgekeurd' => false,
			'status' => 'draft',
			'id' => 'generated-1',
		], $stored);
		$this->assertSame($stored, $this->store->row(schema: 'terugvordering', uuid: 'generated-1'));
	}//end testCreateClawbackCaseStoresADraft()

	/**
	 * A zero or negative amount is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testCreateClawbackCaseRefusesANonPositiveAmount(): void {
		foreach ([0.0, -5.0] as $amount) {
			try {
				$this->service()->createClawbackCase(uitvoeringId: 'u-1', amount: $amount);
				$this->fail('should refuse '.$amount);
			} catch (OCSBadRequestException $e) {
				$this->assertSame('Terugvorderingsbedrag moet positief zijn', $e->getMessage());
			}
		}

		$this->assertSame(0, $this->store->writes);
	}//end testCreateClawbackCaseRefusesANonPositiveAmount()

	/**
	 * A failed save is logged and refused.
	 *
	 * @return void
	 */
	public function testCreateClawbackCaseTurnsAFailedSaveIntoABadRequest(): void {
		$this->store->refusedSchemas = ['terugvordering'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq subsidie: createClawbackCase failed: Save refused for terugvordering');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Kon terugvordering niet aanmaken');
		$this->service(logger: $logger)->createClawbackCase(uitvoeringId: 'u-1', amount: 10.0);
	}//end testCreateClawbackCaseTurnsAFailedSaveIntoABadRequest()
}//end class
