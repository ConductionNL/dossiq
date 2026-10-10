<?php

/**
 * A real RebindValueConverter over a real CaseDateNormaliser, for tests.
 *
 * The normaliser is real so a date answer is judged by the app's one date
 * rule, not by a stub that agrees with whatever the test expects. Only its
 * tenant and clock seams are doubled, the way CaseDateNormaliserTest does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Cases\RebindValueConverter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantConfigurationService;
use OCA\Dossiq\Service\TenantContext;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds the converter for a TestCase.
 */
trait BuildsRebindConverter {

	/**
	 * The converter, over the real date rule in the fallback zone.
	 *
	 * @return RebindValueConverter The converter.
	 */
	private function rebindConverter(): RebindValueConverter {
		$context = $this->createMock(TenantContext::class);
		$context->method('isBound')->willReturn(false);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new RebindValueConverter(
			dates: new CaseDateNormaliser(
				tenantContext: $context,
				tenantConfiguration: $this->createMock(TenantConfigurationService::class),
				settingsService: $settings,
				logger: $this->createMock(LoggerInterface::class),
				time: $this->createMock(ITimeFactory::class),
			)
		);
	}//end rebindConverter()
}//end trait
