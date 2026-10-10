<?php

/**
 * Which case type an intake path opens.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A setting names a case type by identifier or uuid; the case stores the uuid.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeCaseTypes
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class IntakeCaseTypesTest extends TestCase {

	/**
	 * The queries the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A resolver over settings and an object service that knows these case types.
	 *
	 * @param array<string, string> $settings   App settings.
	 * @param array<string, string> $byIdentity Identifier to uuid.
	 * @param LoggerInterface|null  $logger     The logger.
	 *
	 * @return IntakeCaseTypes The resolver.
	 */
	private function resolver(array $settings, array $byIdentity, ?LoggerInterface $logger = null): IntakeCaseTypes {
		$settings = array_merge(['register' => '3', 'case_type_schema' => '7'], $settings);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($settings[$key] ?? $default)
		);

		$objects = new class ($byIdentity, $this->queries) {
			/**
			 * @param array<string, string>            $byIdentity Identifier to uuid.
			 * @param array<int, array<string, mixed>> $queries    Where the queries are recorded.
			 */
			public function __construct(private array $byIdentity, private array &$queries) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query;
				$uuid = ($this->byIdentity[(string)($query['identifier'] ?? '')] ?? null);
				return ($uuid === null) ? [] : [['id' => $uuid, 'identifier' => $query['identifier']]];
			}
		};
		$settingsService->method('getObjectService')->willReturn($objects);

		return new IntakeCaseTypes(
			settingsService: $settingsService,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end resolver()

	/**
	 * Unset, a setting falls back to the shipped case type, found by identifier, once per request.
	 *
	 * @return void
	 */
	public function testTheShippedCaseTypeIsTheDefault(): void {
		$resolver = $this->resolver(settings: [], byIdentity: ['bezwaar-behandeling' => 'aaaaaaaa-1111-4111-a111-111111111111']);

		$this->assertSame('aaaaaaaa-1111-4111-a111-111111111111', $resolver->caseTypeFor(setting: IntakeCaseTypes::OBJECTION));
		$this->assertSame('aaaaaaaa-1111-4111-a111-111111111111', $resolver->caseTypeFor(setting: IntakeCaseTypes::OBJECTION));
		$this->assertCount(1, $this->queries);
		$this->assertSame(['register' => 3, 'schema' => 7], $this->queries[0]['@self']);
	}//end testTheShippedCaseTypeIsTheDefault()

	/**
	 * An instance points the path at its own case type; a uuid is taken as it is.
	 *
	 * @return void
	 */
	public function testAConfiguredCaseTypeWins(): void {
		$resolver = $this->resolver(
			settings: [IntakeCaseTypes::COMPLAINT => 'cccccccc-2222-4222-a222-222222222222'],
			byIdentity: []
		);

		$this->assertSame('cccccccc-2222-4222-a222-222222222222', $resolver->caseTypeFor(setting: IntakeCaseTypes::COMPLAINT));
		$this->assertSame([], $this->queries);
	}//end testAConfiguredCaseTypeWins()

	/**
	 * A reference nothing answers to is the empty string, and it is logged.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIsEmptyAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$resolver = $this->resolver(settings: [IntakeCaseTypes::COMPLAINT => 'gone'], byIdentity: [], logger: $logger);

		$this->assertSame('', $resolver->caseTypeFor(setting: IntakeCaseTypes::COMPLAINT));
	}//end testAnUnknownCaseTypeIsEmptyAndLogged()
}//end class
