<?php

/**
 * Unit tests for TemplateAdapterChoice: a named template adapter is used as
 * named; otherwise filinq's adapter when filinq (or its former id) is enabled,
 * and the mock adapter when it is not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\FilinqTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateAdapterChoice;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\TemplateAdapterChoice
 *
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class TemplateAdapterChoiceTest extends TestCase {

	private function choice(array $installed, array $enabled): TemplateAdapterChoice {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => in_array($id, $installed, true));
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $id): bool => in_array($id, $enabled, true));
		return new TemplateAdapterChoice($apps);
	}//end choice()

	public function testANamedAdapterIsUsedAsNamed(): void {
		$this->assertSame('OCA\\Acme\\Adapter', $this->choice(['filinq'], ['filinq'])->adapterFor('  OCA\\Acme\\Adapter '));
	}//end testANamedAdapterIsUsedAsNamed()

	public function testFilinqsAdapterWhenFilinqOrItsFormerIdIsEnabled(): void {
		$this->assertSame(FilinqTemplateEngineAdapter::class, $this->choice(['filinq'], ['filinq'])->adapterFor(''));
		$this->assertSame(FilinqTemplateEngineAdapter::class, $this->choice(['docudesk'], ['docudesk'])->adapterFor(' '));
	}//end testFilinqsAdapterWhenFilinqOrItsFormerIdIsEnabled()

	public function testTheMockAdapterWhenFilinqIsAbsentOrDisabled(): void {
		$this->assertSame(MockTemplateEngineAdapter::class, $this->choice([], [])->adapterFor(''));
		$this->assertSame(MockTemplateEngineAdapter::class, $this->choice(['filinq'], [])->adapterFor(''));
	}//end testTheMockAdapterWhenFilinqIsAbsentOrDisabled()
}//end class
