<?php

/**
 * Unit tests for WooRequesterProperties: a Woo request's requester answers are
 * stored as case properties only where the case type defines a property of
 * that name, read without the caller's scoping.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooRequesterProperties;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooRequesterProperties
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooRequesterPropertiesTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An object service answering the given property definitions and recording its searches.
	 *
	 * @param list<mixed>|null $rows The definitions, or null to throw.
	 */
	private function objectService(?array $rows): object {
		return new class($rows) {
			/** @var list<array<int|string, mixed>> */
			public array $searches = [];

			public function __construct(private ?array $rows) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = [$register, $schema, $filters, $_rbac, $_multitenancy];
				if ($this->rows === null) {
					throw new RuntimeException('index down');
				}

				return $this->rows;
			}
		};
	}//end objectService()

	public function testOnlyAnswersTheCaseTypeDefinesBecomeProperties(): void {
		$this->settings->method('getConfigValue')->with('property_definition_schema')->willReturn('property_definition');
		$service = $this->objectService(
			[
				['name' => 'verzoekerNaam', '@self' => ['id' => 'pd-1'], 'id' => 'ignored'],
				['name' => ' verzoekerEmail ', 'uuid' => 'pd-2'],
				['name' => 'verzoekerType', 'id' => ' '],
				['name' => '', 'id' => 'pd-4'],
			]
		);

		$entries = (new WooRequesterProperties($this->settings, $this->logger))->forCaseType(
			$service,
			'dossiq',
			'ct-1',
			['verzoekerNaam' => ' J. Jansen ', 'verzoekerEmail' => 'j@example.nl', 'verzoekerType' => 'burger', 'subject' => 'x']
		);

		$this->assertSame(
			[
				['propertyDefinition' => 'pd-1', 'name' => 'verzoekerNaam', 'value' => 'J. Jansen'],
				['propertyDefinition' => 'pd-2', 'name' => 'verzoekerEmail', 'value' => 'j@example.nl'],
			],
			$entries
		);
		$this->assertSame([['dossiq', 'property_definition', ['caseType' => 'ct-1'], false, false]], $service->searches);
	}//end testOnlyAnswersTheCaseTypeDefinesBecomeProperties()

	public function testWithoutRequesterAnswersNothingIsRead(): void {
		$this->settings->expects($this->never())->method('getConfigValue');
		$service = $this->objectService([]);

		$this->assertSame(
			[],
			(new WooRequesterProperties($this->settings, $this->logger))->forCaseType($service, 'dossiq', 'ct-1', ['verzoekerNaam' => '  ', 'subject' => 'x'])
		);
		$this->assertSame([], $service->searches);
	}//end testWithoutRequesterAnswersNothingIsRead()

	public function testWithoutADefinitionSchemaNothingIsStored(): void {
		$this->settings->method('getConfigValue')->willReturn('');
		$service = $this->objectService([['name' => 'verzoekerNaam', 'id' => 'pd-1']]);

		$this->assertSame(
			[],
			(new WooRequesterProperties($this->settings, $this->logger))->forCaseType($service, 'dossiq', 'ct-1', ['verzoekerNaam' => 'J. Jansen'])
		);
		$this->assertSame([], $service->searches);
	}//end testWithoutADefinitionSchemaNothingIsStored()

	public function testAFailingReadIsLoggedAndKeepsTheDetailsOnTheRequestOnly(): void {
		$this->settings->method('getConfigValue')->willReturn('property_definition');
		$this->logger->expects($this->once())->method('warning')->with(
			'WooRequestIntake: the case type property definitions could not be read, so the requester details are kept on the request only',
			['error' => 'index down']
		);

		$this->assertSame(
			[],
			(new WooRequesterProperties($this->settings, $this->logger))->forCaseType($this->objectService(null), 'dossiq', 'ct-1', ['verzoekerNaam' => 'J. Jansen'])
		);
	}//end testAFailingReadIsLoggedAndKeepsTheDetailsOnTheRequestOnly()
}//end class
