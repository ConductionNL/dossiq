<?php

/**
 * Unit test for ServiceRegistrar.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registrar;

use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\External\Brk\BrkAdapterInterface;
use OCA\Dossiq\Service\External\Woz\WozAdapterInterface;
use OCA\Dossiq\Service\Registrar\ServiceRegistrar;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The service registrar runs every sub-registrar, so each seam they own is bound.
 *
 * @covers \OCA\Dossiq\Service\Registrar\ServiceRegistrar
 * @uses \OCA\Dossiq\AppInfo\OpenRegisterAutoloader
 * @uses \OCA\Dossiq\Dashboard\DashboardWidgetList
 * @uses \OCA\Dossiq\Listener\Registrar\SaasServiceRegistrar
 * @uses \OCA\Dossiq\Middleware\MiddlewareRegistrar
 * @uses \OCA\Dossiq\Repair\RepairPortRegistrar
 * @uses \OCA\Dossiq\Service\ContractBindings
 * @uses \OCA\Dossiq\Service\Registrar\AppHostRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\BeschikkingAdapterRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\BespokeServiceRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\BrkRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\ExternalRegisterRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\MailIntakeRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\SubstitutableAdapterRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\WozRegistrar
 */
class ServiceRegistrarTest extends TestCase {

	/**
	 * One registration pass binds the seams of every sub-registrar, each once.
	 *
	 * @return void
	 */
	public function testEverySubRegistrarRuns(): void {
		Bootstrap::$adoptions = [];
		$services = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$services): void {
				$services[] = $name;
			}
		);

		(new ServiceRegistrar())->register(context: $context);

		foreach ([SettingsService::class, BrkAdapterInterface::class, WozAdapterInterface::class, FilterPipeline::class] as $seam) {
			$this->assertSame(1, count(array_keys($services, $seam, true)), $seam . ' is bound once');
		}

		// The AppHost adoption is part of the same pass.
		$this->assertCount(1, Bootstrap::$adoptions);
	}//end testEverySubRegistrarRuns()
}//end class
