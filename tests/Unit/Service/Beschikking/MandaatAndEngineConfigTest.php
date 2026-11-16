<?php

/**
 * Tests for the configuration edges of the mandate verifier and the template engine choice.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\DecisionTemplateEngine;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A half-configured mandate register and a malformed decision-kind map.
 */
#[CoversClass(MandaatVerifier::class)]
#[CoversClass(DecisionTemplateEngine::class)]
class MandaatAndEngineConfigTest extends TestCase {

	/**
	 * A register or schema left empty reads no scheme, and the store is never searched.
	 *
	 * @return void
	 */
	public function testAHalfConfiguredMandateRegisterHasNoScheme(): void {
		foreach ([['register' => 'dossiq'], ['mandaat_regeling_schema' => 'mandaat']] as $config) {
			$store = new class {
				/**
				 * Searches made.
				 *
				 * @var int
				 */
				public int $searches = 0;

				/**
				 * Record a search.
				 *
				 * @param string               $register The register.
				 * @param string               $schema   The schema.
				 * @param array<string, mixed> $filters  The filters.
				 *
				 * @return array<int, array<string, mixed>> A scheme naming every case type.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
					$this->searches++;
					return [['mandateGroups' => [['caseTypes' => []]]]];
				}
			};
			$settings = $this->createMock(SettingsService::class);
			$settings->method('getObjectService')->willReturn($store);
			$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

			$verifier = new MandaatVerifier($settings, $this->createMock(LoggerInterface::class));

			$this->assertSame([], $verifier->resolveMandaatRegeling('vergunning'));
			$this->assertSame(0, $store->searches);
		}
	}//end testAHalfConfiguredMandateRegisterHasNoScheme()

	/**
	 * A case type whose decision-kind map is not a list renders with filinq.
	 *
	 * @return void
	 */
	public function testAMalformedKindMapRendersWithFilinq(): void {
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->with('case-1')->willReturn(['caseType' => 'ct-1']);
		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->expects($this->once())->method('effectiveCaseType')->with('ct-1')
			->willReturn(['id' => 'ct-1', 'decisionDocuments' => 'vendor:xential']);

		$engines = new DecisionTemplateEngine($store, $caseTypes, $this->createMock(LoggerInterface::class));

		$this->assertSame(DecisionTemplateEngine::DEFAULT_ENGINE, $engines->engineFor('case-1', 'tpl-grant'));
		$this->assertSame('filinq', DecisionTemplateEngine::DEFAULT_ENGINE);
	}//end testAMalformedKindMapRendersWithFilinq()
}//end class
