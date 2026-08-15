<?php

/**
 * VaststellingService Unit Tests.
 *
 * Exercises the settlement math (REQ-SUB-005): accountantsverklaring
 * threshold, final-bedrag capping, overpayment detection and the
 * terugvordering trigger boundary.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\TerugvorderingService;
use OCA\Procest\Service\Subsidie\VaststellingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService fake for VaststellingServiceTest: plain
 * find(id, register, schema) / saveObject(object, register, schema, uuid)
 * over a schema-keyed store. saveObject merges the given fields onto any
 * existing row (matching finalize()'s own pre-existing partial-patch call
 * for the vaststelling object itself).
 */
class VaststellingFakeObjectService {

	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Find one object by id within a schema.
	 *
	 * @param string $id Object id.
	 * @param string $register Ignored (single in-memory register).
	 * @param string $schema Schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register, string $schema): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end find()

	/**
	 * Save (merge) an object into the store.
	 *
	 * @param array<string, mixed> $object Fields to merge.
	 * @param string $register Ignored.
	 * @param string $schema Schema slug.
	 * @param string|null $uuid Object id (null = generate one).
	 *
	 * @return array<string, mixed> The merged row.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
		$uuid = ($uuid ?? ('generated-' . count($this->store[$schema] ?? [])));
		$existing = ($this->store[$schema][$uuid] ?? []);
		$merged = array_merge($existing, $object, ['id' => $uuid]);
		$this->store[$schema][$uuid] = $merged;

		return $merged;
	}//end saveObject()
}//end class

/**
 * @covers \OCA\Procest\Service\Subsidie\VaststellingService
 *
 * @uses \OCA\Procest\Service\Subsidie\TerugvorderingService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-20
 * @spec openspec/changes/subsidie-settlement-case-costs/specs/subsidie-settlement-case-costs/spec.md
 */
class VaststellingServiceTest extends TestCase {

	private VaststellingService $service;

	/**
	 * The in-memory object store fake, shared with $settings for the
	 * finalize()-focused tests.
	 *
	 * @var VaststellingFakeObjectService
	 */
	private VaststellingFakeObjectService $objects;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new VaststellingFakeObjectService();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'procest',
					'subsidie_vaststelling_schema' => 'subsidieVaststelling',
					'subsidie_uitvoering_schema' => 'subsidieUitvoering',
					'subsidie_aanvraag_schema' => 'subsidieAanvraag',
					'case_schema' => 'case',
					'terugvordering_schema' => 'terugvordering',
					default => $default,
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$terugvordering = new TerugvorderingService($settings, $logger);
		$this->service = new VaststellingService($settings, $terugvordering, $logger);
	}//end setUp()

	/**
	 * Seed the full subsidieUitvoering -> subsidieAanvraag -> case chain.
	 *
	 * @param string $determinationId Vaststelling id.
	 * @param string $uitvoeringId Execution id.
	 * @param string $requestId Application id.
	 * @param string|null $caseId Linked case id (null = no case link).
	 *
	 * @return void
	 */
	private function seedChain(string $determinationId, string $uitvoeringId, string $requestId, ?string $caseId): void {
		$this->objects->store['subsidieVaststelling'][$determinationId] = [
			'id' => $determinationId,
			'subsidieuitvoering' => $uitvoeringId,
			'status' => 'concept',
		];
		$this->objects->store['subsidieUitvoering'][$uitvoeringId] = [
			'id' => $uitvoeringId,
			'subsidieaanvraag' => $requestId,
		];
		$this->objects->store['subsidieAanvraag'][$requestId] = [
			'id' => $requestId,
			'case' => ($caseId ?? ''),
		];

		if ($caseId !== null) {
			$this->objects->store['case'][$caseId] = ['id' => $caseId, 'title' => 'Test case'];
		}
	}//end seedChain()

	/**
	 * @return void
	 */
	public function testAccountantsverklaringThreshold(): void {
		$this->assertTrue($this->service->accountantsverklaringVereist(150000.0, 125000.0));
		$this->assertFalse($this->service->accountantsverklaringVereist(125000.0, 125000.0));
		$this->assertFalse($this->service->accountantsverklaringVereist(100000.0, 125000.0));
	}//end testAccountantsverklaringThreshold()

	/**
	 * Final bedrag is capped at the granted amount and never above actual costs.
	 *
	 * @return void
	 */
	public function testVastgesteldBedragCapping(): void {
		// Actual costs below granted -> settle at actual costs.
		$this->assertSame(330000.0, $this->service->computeVastgesteldBedrag(450000.0, 330000.0));
		// Actual costs above granted -> capped at granted.
		$this->assertSame(450000.0, $this->service->computeVastgesteldBedrag(450000.0, 500000.0));
		// Negative actual costs guarded to zero.
		$this->assertSame(0.0, $this->service->computeVastgesteldBedrag(450000.0, -1.0));
	}//end testVastgesteldBedragCapping()

	/**
	 * REQ-SUB-005: overpayment is the positive difference between disbursed
	 * advances and the final settled amount.
	 *
	 * @return void
	 */
	public function testOverpaymentAndTrigger(): void {
		// €360.000 advances vs €330.000 settled -> €30.000 clawback.
		$this->assertSame(30000.0, $this->service->computeOverpayment(360000.0, 330000.0));
		$this->assertTrue($this->service->recoveryTrigger(360000.0, 330000.0));

		// Advances equal to settled -> no clawback.
		$this->assertSame(0.0, $this->service->computeOverpayment(330000.0, 330000.0));
		$this->assertFalse($this->service->recoveryTrigger(330000.0, 330000.0));

		// Settled above advances (under-disbursed) -> no clawback.
		$this->assertSame(0.0, $this->service->computeOverpayment(300000.0, 330000.0));
		$this->assertFalse($this->service->recoveryTrigger(300000.0, 330000.0));
	}//end testOverpaymentAndTrigger()

	/**
	 * subsidie-settlement-case-costs: no linked case — finalize() still
	 * succeeds (vaststelling is patched) and simply does not append kosten
	 * anywhere, rather than throwing.
	 *
	 * @return void
	 */
	public function testFinalizeWithNoLinkedCaseDoesNotThrow(): void {
		$this->seedChain(determinationId: 'vst-2', uitvoeringId: 'uitv-2', requestId: 'aanv-2', caseId: null);

		$result = $this->service->finalize(determinationId: 'vst-2', grantedAmount: 100000.0, actualCost: 80000.0, totalAdvances: 50000.0);

		$this->assertSame('vastgesteld', $result['vaststelling']['status']);
		$this->assertSame([], $this->objects->store['case'] ?? []);
	}//end testFinalizeWithNoLinkedCaseDoesNotThrow()
}//end class
