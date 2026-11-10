<?php

/**
 * Dossiq adopts OpenRegister's AppHost with one LeafAdoption.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\AppInfo\Registrar\AppHostRegistrar;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\AppHostRegistrar
 */
class AppHostRegistrarTest extends TestCase {

	/**
	 * The registrar hands the AppHost one adoption carrying dossiq's id, namespace and section.
	 *
	 * @return void
	 */
	public function testTheLeafIsAdoptedWithItsIdNamespaceAndSection(): void {
		Bootstrap::$adoptions = [];

		(new AppHostRegistrar())->register(context: $this->createMock(IRegistrationContext::class));

		$this->assertCount(1, Bootstrap::$adoptions);
		$adoption = Bootstrap::$adoptions[0];
		$this->assertSame(Application::APP_ID, $adoption->appId);
		$this->assertSame('OCA\\Dossiq\\Controller', $adoption->namespace(option: 'controllerNamespace', suffix: 'Controller'));
		$this->assertSame('Dossiq', $adoption->section()['name']);
	}//end testTheLeafIsAdoptedWithItsIdNamespaceAndSection()
}//end class
