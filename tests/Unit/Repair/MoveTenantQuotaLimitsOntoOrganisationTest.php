<?php

/**
 * Stored tenant quota rows are brought in line with decision 272 on upgrade.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MoveTenantQuotaLimitsOntoOrganisation;
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantQuotaService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Repair\MoveTenantQuotaLimitsOntoOrganisation
 */
class MoveTenantQuotaLimitsOntoOrganisationTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $saved = [];

	/** @var list<array{tenantId: string, limit: int|null}> */
	private array $organisationWrites = [];

	private ?string $done = null;

	/**
	 * The step over the given stored rows.
	 *
	 * @param list<array<string, mixed>> $rows           The tenantQuota rows.
	 * @param int|null                   $orgUserQuota   What the Organisation's userQuota reads.
	 * @param bool                       $orgWritable    Whether the Organisation can be written.
	 *
	 * @return MoveTenantQuotaLimitsOntoOrganisation
	 */
	private function step(array $rows, ?int $orgUserQuota = null, bool $orgWritable = true): MoveTenantQuotaLimitsOntoOrganisation {
		$test = $this;
		$store = new class ($rows, $test) {
			/**
			 * @param list<array<string, mixed>> $rows
			 */
			public function __construct(private array $rows, private MoveTenantQuotaLimitsOntoOrganisationTest $test) {
			}

			/**
			 * @param array<string, mixed> $config
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config): array {
				$type = $config['filters']['quotaType'];
				return array_values(array_filter($this->rows, static fn (array $row): bool => $row['quotaType'] === $type));
			}

			/**
			 * @param array<string, mixed> $object
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				$this->test->recordSave(row: $object + ['@uuid' => $uuid]);
				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => (string)$this->done);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->done = $value;
			return true;
		});

		$limits = $this->createMock(OrganisationQuotaLimits::class);
		$limits->method('read')->willReturn($orgUserQuota);
		$limits->method('write')->willReturnCallback(function (string $tenantId, string $quotaType, ?int $limit) use ($orgWritable): bool {
			$this->organisationWrites[] = ['tenantId' => $tenantId, 'limit' => $limit];
			return $orgWritable;
		});

		$quotas = $this->createMock(TenantQuotaService::class);
		$quotas->method('quotaRowAsArray')->willReturnCallback(static fn (mixed $row): ?array => $row);

		return new MoveTenantQuotaLimitsOntoOrganisation($settings, $config, $limits, $quotas, new NullLogger());
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function recordSave(array $row): void {
		$this->saved[] = $row;
	}

	public function testAStorageRowIsRenamedAndItsUsageCountedInMegabytes(): void {
		$this->step(rows: [['id' => 'q-1', 'tenantRef' => 't-1', 'quotaType' => 'storage_gb', 'limit' => 10, 'currentUsage' => 3]])
			->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->saved);
		$this->assertSame('storage_mb', $this->saved[0]['quotaType']);
		$this->assertSame(3072, $this->saved[0]['currentUsage']);
		$this->assertArrayNotHasKey('limit', $this->saved[0]);
		$this->assertSame('q-1', $this->saved[0]['@uuid']);
		$this->assertSame(MoveTenantQuotaLimitsOntoOrganisation::DONE, $this->done);
	}

	public function testAUserLimitMovesOntoAnOrganisationThatHasNone(): void {
		$this->step(rows: [['id' => 'q-2', 'tenantRef' => 't-1', 'quotaType' => 'active_users', 'limit' => 5, 'currentUsage' => 2]])
			->run($this->createMock(IOutput::class));

		$this->assertSame([['tenantId' => 't-1', 'limit' => 5]], $this->organisationWrites);
		$this->assertArrayNotHasKey('limit', $this->saved[0]);
		$this->assertSame(2, $this->saved[0]['currentUsage']);
	}

	public function testAnOrganisationUserLimitAlreadySetIsNeverOverwritten(): void {
		$this->step(rows: [['id' => 'q-2', 'tenantRef' => 't-1', 'quotaType' => 'active_users', 'limit' => 5]], orgUserQuota: 50)
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->organisationWrites);
		$this->assertCount(1, $this->saved);
	}

	public function testARowWhoseOrganisationCannotBeWrittenKeepsItsLimitAndTheStepRunsAgain(): void {
		$this->step(rows: [['id' => 'q-2', 'tenantRef' => 'gone', 'quotaType' => 'active_users', 'limit' => 5]], orgWritable: false)
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->saved);
		$this->assertNull($this->done);
	}

	public function testRowsThatNeedNothingAreLeftAlone(): void {
		$this->step(rows: [['id' => 'q-3', 'tenantRef' => 't-1', 'quotaType' => 'active_users', 'currentUsage' => 2]])
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->saved);
		$this->assertSame(MoveTenantQuotaLimitsOntoOrganisation::DONE, $this->done);
	}

	public function testACompletedRunDoesNothing(): void {
		$this->done = MoveTenantQuotaLimitsOntoOrganisation::DONE;
		$this->step(rows: [['id' => 'q-1', 'tenantRef' => 't-1', 'quotaType' => 'storage_gb', 'currentUsage' => 1]])
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->saved);
	}
}
