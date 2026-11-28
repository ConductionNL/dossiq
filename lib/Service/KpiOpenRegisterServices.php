<?php

/**
 * OpenRegister's object and language services, for the KPI figures, when OpenRegister is installed.
 *
 * Both are optional: without OpenRegister the figures read as empty, and a
 * language service without the translation switch is passed over. An object
 * service that does not resolve on an installed OpenRegister is logged.
 * Split out of {@see KpiAggregationService}, which keeps the figures.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves OpenRegister's object and language services when it is installed.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class KpiOpenRegisterServices {

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Establishes that OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves its services.
	 * @param LoggerInterface    $logger     Logs an object service that did not resolve.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister's request-scoped language service, or null when OpenRegister
	 * is absent or the service cannot answer for all translations.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function languageService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		$serviceId = 'OCA\OpenRegister\Service\LanguageService';
		if ($this->container->has($serviceId) === false) {
			return null;
		}

		$service = $this->container->get($serviceId);
		if (is_object($service) === false
			|| method_exists($service, 'setReturnAllTranslations') === false
			|| method_exists($service, 'shouldReturnAllTranslations') === false
		) {
			return null;
		}

		return $service;
	}//end languageService()

	/**
	 * OpenRegister's ObjectService, or null when the app is absent.
	 *
	 * A runtime lookup guarded by isInstalled(), per ADR-083: naming the class in
	 * a constructor property would make PHP resolve it on every construction, so
	 * an instance without OpenRegister would fail with an error about a class
	 * nobody mentioned.
	 *
	 * @return object|null The ObjectService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ObjectService|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function objectService(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[KpiAggregationService] OpenRegister is installed but its ObjectService did not resolve',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end objectService()
}//end class
