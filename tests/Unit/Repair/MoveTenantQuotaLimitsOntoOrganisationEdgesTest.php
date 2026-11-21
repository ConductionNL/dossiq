<?php

/**
 * The tenant quota rewrite on the paths the main test leaves alone: no
 * OpenRegister, rows that cannot be read, a row without a uuid, and a row
 * whose save fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\OrganisationQuotaLimitsInterface;
use OCA\Dossiq\Kernel\Contracts\TenantQuotaServiceInterface;
use OCA\Dossiq\Repair\MoveTenantQuotaLimitsOntoOrganisation;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MoveTenantQuotaLimitsOntoOrganisation
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class MoveTenantQuotaLimitsOntoOrganisationEdgesTest extends TestCase {

	/**
	 * Whether the completion key was written.
	 *
	 * @var string|null
	 */
	private ?string $done = null;

	/**
	 * Output lines.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * A store over the given rows; findAll throws when $rows is null, saving
	 * the row of tenant 'refused' throws.
	 *
	 * @param list<array<string, mixed>>|null $rows The rows.
	 *
	 * @return object The fake.
	 */
	private function store(?array $rows): object {
		return new class($rows) {
			/**
			 * @var list<array{tenant: string, uuid: string|null}>
			 */
			public array $saves = [];

			/**
			 * @param list<array<string, mixed>>|null $rows The rows.
			 */
			public function __construct(private readonly ?array $rows) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<array<string, mixed>>
			 *
			 * @throws RuntimeException When the rows are unreadable.
			 */
			public function findAll(array $config): array {
				if ($this->rows === null) {
					throw new RuntimeException('quota table is locked');
				}

				$type = $config['filters']['quotaType'];
				return array_values(array_filter($this->rows, static fn (array $row): bool => $row['quotaType'] === $type));
			}

			/**
			 * @param array<string, mixed> $object  The row.
			 * @param ObjectSaveOptions    $options Where it goes.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException For the refused tenant.
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): array {
				if ($object['tenantRef'] === 'refused') {
					throw new RuntimeException('validation failed');
				}

				$this->saves[] = ['tenant' => $object['tenantRef'], 'uuid' => $options->uuid];
				return $object;
			}
		};
	}//end store()

	/**
	 * The step over a store.
	 *
	 * @param object|null     $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return MoveTenantQuotaLimitsOntoOrganisation The step.
	 */
	private function step(?object $store, LoggerInterface $logger): MoveTenantQuotaLimitsOntoOrganisation {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => (string)$this->done);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->done = $value;
			return true;
		});

		$quotas = $this->createMock(originalClassName: TenantQuotaServiceInterface::class);
		$quotas->method('quotaRowAsArray')->willReturnCallback(static fn (mixed $row): ?array => $row);

		return new MoveTenantQuotaLimitsOntoOrganisation(
			settings: $settings,
			appConfig: $config,
			organisationLimits: $this->createMock(originalClassName: OrganisationQuotaLimitsInterface::class),
			quotas: $quotas,
			logger: $logger
		);
	}//end step()

	/**
	 * Run a step and record what it said.
	 *
	 * @param MoveTenantQuotaLimitsOntoOrganisation $step The step.
	 *
	 * @return void
	 */
	private function runStep(MoveTenantQuotaLimitsOntoOrganisation $step): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		$step->run($output);
	}//end runStep()

	/**
	 * Without OpenRegister the step says so and stays unmarked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkipsAndStaysUnmarked(): void {
		$this->runStep(step: $this->step(store: null, logger: $this->createMock(originalClassName: LoggerInterface::class)));

		$this->assertSame(expected: ['Tenant quotas: OpenRegister is not available, skipped.'], actual: $this->said);
		$this->assertNull(actual: $this->done);
	}//end testWithoutOpenRegisterItSkipsAndStaysUnmarked()

	/**
	 * Rows that cannot be read are logged, said, and leave the step unmarked.
	 *
	 * @return void
	 */
	public function testUnreadableRowsAreLoggedAndTheStepStaysUnmarked(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: tenant quota rows could not be read',
			['error' => 'quota table is locked']
		);

		$this->runStep(step: $this->step(store: $this->store(rows: null), logger: $logger));

		$this->assertSame(expected: ['Tenant quotas: the rows could not be read, skipped.'], actual: $this->said);
		$this->assertNull(actual: $this->done);
	}//end testUnreadableRowsAreLoggedAndTheStepStaysUnmarked()

	/**
	 * A row without a uuid is saved as a new row; a row whose save fails is
	 * logged with its tenant, counted as failed, and keeps the step unmarked.
	 *
	 * @return void
	 */
	public function testARowWithoutAUuidIsSavedAndAFailedSaveKeepsTheStepUnmarked(): void {
		$store = $this->store(
			rows: [
				['tenantRef' => 'no-uuid', 'quotaType' => 'storage_gb', 'currentUsage' => 2, 'limit' => 10],
				['tenantRef' => 'refused', 'uuid' => 'q-2', 'quotaType' => 'storage_gb', 'currentUsage' => 1, 'limit' => 5],
			]
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: a tenant quota row was not rewritten',
			['tenantId' => 'refused', 'error' => 'validation failed']
		);

		$this->runStep(step: $this->step(store: $store, logger: $logger));

		$this->assertSame(expected: [['tenant' => 'no-uuid', 'uuid' => null]], actual: $store->saves);
		$this->assertSame(
			expected: ['Tenant quotas: 1 storage row(s) now in MB, 0 user limit(s) moved onto the organisation, 1 failed.'],
			actual: $this->said
		);
		$this->assertNull(actual: $this->done);
	}//end testARowWithoutAUuidIsSavedAndAFailedSaveKeepsTheStepUnmarked()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(
			expected: 'Count tenant storage in megabytes and move user limits onto the organisation',
			actual: $this->step(store: null, logger: $this->createMock(originalClassName: LoggerInterface::class))->getName()
		);
	}//end testTheStepNamesItself()
}//end class
