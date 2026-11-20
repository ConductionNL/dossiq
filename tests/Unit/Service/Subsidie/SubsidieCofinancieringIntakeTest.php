<?php

/**
 * SubsidieService: an application whose co-financing does not add up is refused at intake.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * createAanvraag() consults the co-financing validator only when both halves are declared.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\SubsidieService
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup
 * @uses   \OCA\Dossiq\Service\Subsidie\CofinancieringValidator
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\RowList
 */
class SubsidieCofinancieringIntakeTest extends TestCase {

	/**
	 * The register the applications land in.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The service over the in-memory register.
	 *
	 * @return SubsidieService
	 */
	private function service(): SubsidieService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'subsidie_aanvraag_schema' => 'subsidieAanvraag',
				default => $default,
			}
		);

		return new SubsidieService(settingsService: $settings, logger: new NullLogger());
	}//end service()

	/**
	 * A budget of cost items that the subsidy plus the co-financing covers is accepted and stored.
	 *
	 * @return void
	 */
	public function testAReconcilingBudgetIsAccepted(): void {
		$stored = $this->service()->createAanvraag(payload: [
			'subsidyScheme' => 'regeling-1',
			'requestedAmount' => 6000,
			'coFinancingList' => [['partij' => 'Provincie', 'bedrag' => 3000], ['partij' => 'Fonds', 'bedrag' => 1000]],
			'budget' => [['kostenpost' => 'Personeel', 'bedrag' => 7000], ['kostenpost' => 'Materiaal', 'bedrag' => 3000]],
		]);

		$this->assertSame(expected: 'received', actual: $stored['status']);
		$this->assertCount(expectedCount: 1, haystack: $this->store->all(schema: 'subsidieAanvraag'));
	}//end testAReconcilingBudgetIsAccepted()

	/**
	 * A budget that the subsidy plus co-financing does not cover is refused with the validator's code, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAMismatchedBudgetIsRefusedWithTheValidatorsCode(): void {
		try {
			$this->service()->createAanvraag(payload: [
				'subsidyScheme' => 'regeling-1',
				'requestedAmount' => 5000,
				'coFinancingList' => json_encode([['partij' => 'Provincie', 'bedrag' => 3000]]),
				'budget' => [['kostenpost' => 'Personeel', 'bedrag' => 10000]],
			]);
			$this->fail(message: 'Expected the application to be refused.');
		} catch (OCSBadRequestException $e) {
			$this->assertSame(
				expected: 'De cofinanciering sluit niet aan op het projecttotaal (COFIN_SUM_MISMATCH)',
				actual: $e->getMessage()
			);
		}

		$this->assertSame(expected: [], actual: $this->store->all(schema: 'subsidieAanvraag'));
	}//end testAMismatchedBudgetIsRefusedWithTheValidatorsCode()

	/**
	 * Co-financing without a project total is passed through untouched.
	 *
	 * @return void
	 */
	public function testCoFinancingWithoutABudgetIsNotChecked(): void {
		$stored = $this->service()->createAanvraag(payload: [
			'subsidyScheme' => 'regeling-1',
			'requestedAmount' => 5000,
			'coFinancingList' => [['partij' => 'Provincie', 'bedrag' => 3000]],
		]);

		$this->assertSame(expected: 'received', actual: $stored['status']);
	}//end testCoFinancingWithoutABudgetIsNotChecked()
}//end class
