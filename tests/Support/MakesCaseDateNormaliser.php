<?php

/**
 * Test helper for the one date write path.
 *
 * Every write-path test needs a normaliser bound to a known zone. Building it
 * in one place keeps the zone under test explicit and stops each suite
 * inventing its own tenant.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * Builds a CaseDateNormaliser whose tenant answers a stated zone.
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */
trait MakesCaseDateNormaliser {
	/**
	 * A normaliser reading the given administered zone.
	 *
	 * @param string $zone The IANA identifier the tenant administers.
	 *
	 * @return CaseDateNormaliser
	 */
	private function caseDates(string $zone = 'Europe/Amsterdam'): CaseDateNormaliser {
		$context = $this->createMock(originalClassName: TenantContext::class);
		$context->method('isBound')->willReturn(true);
		$context->method('getTenantId')->willReturn('tenant-under-test');

		$configuration = $this->createMock(originalClassName: TenantConfigurationService::class);
		$configuration->method('getConfig')->willReturn(['timezone' => $zone]);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new CaseDateNormaliser(
			tenantContext: $context,
			tenantConfiguration: $configuration,
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end caseDates()
}//end trait
