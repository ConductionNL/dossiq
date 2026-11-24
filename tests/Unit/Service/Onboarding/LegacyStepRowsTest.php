<?php

/**
 * Unit tests for carrying legacy onboarding rows onto the task engine.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Onboarding
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Onboarding;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\Onboarding\LegacyStepRows;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Onboarding\LegacyStepRows
 * @uses   \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class LegacyStepRowsTest extends TestCase {

	/**
	 * An object service whose findAll() answers the given rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return object The object service.
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * The rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}
		};
	}//end store()

	/**
	 * Only the steps the engine accepted are counted.
	 *
	 * @return void
	 */
	public function testOnlyAcceptedStepsCount(): void {
		$steps = $this->createMock(originalClassName: OnboardingSteps::class);
		$steps->method('open')->willReturnMap(
			valueMap: [
				['tenant-a', 'contract', 'contract', 'pending', 'anna', ['onboardingRow' => 'row-1'], 'task-1'],
				['tenant-a', 'branding', 'branding', 'pending', 'anna', ['onboardingRow' => 'row-2'], ''],
			]
		);
		$carrier = new LegacyStepRows(
			steps: $steps,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			rowNormaliser: new OpenRegisterRowNormaliser()
		);

		$carried = $carrier->carry(
			objectService: $this->store(
				rows: [
					['uuid' => 'row-1', 'tenantRef' => 'tenant-a', 'step' => 'contract'],
					['uuid' => 'row-2', 'tenantRef' => 'tenant-a', 'step' => 'branding'],
				]
			),
			tenantId: 'tenant-a',
			actor: 'anna'
		);

		self::assertSame(expected: 1, actual: $carried);
	}//end testOnlyAcceptedStepsCount()

	/**
	 * Without OpenRegister nothing is read or opened.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceNothingIsCarried(): void {
		$steps = $this->createMock(originalClassName: OnboardingSteps::class);
		$steps->expects($this->never())->method('open');
		$carrier = new LegacyStepRows(
			steps: $steps,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			rowNormaliser: new OpenRegisterRowNormaliser()
		);

		self::assertSame(expected: 0, actual: $carrier->carry(objectService: null, tenantId: 'tenant-a', actor: 'anna'));
	}//end testWithoutAnObjectServiceNothingIsCarried()
}//end class
