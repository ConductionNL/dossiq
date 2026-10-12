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
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A setting names a case type by identifier or uuid; the case stores the uuid.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeCaseTypes
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
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
	 * Unset, a path opens the shipped case type, found by identifier, once per request.
	 *
	 * @return void
	 */
	public function testTheShippedCaseTypeIsTheDefault(): void {
		$resolver = $this->resolver(settings: [], byIdentity: ['bezwaar' => 'aaaaaaaa-1111-4111-a111-111111111111']);

		$this->assertSame('aaaaaaaa-1111-4111-a111-111111111111', $resolver->caseTypeFor(path: 'createBezwaar'));
		$this->assertSame('aaaaaaaa-1111-4111-a111-111111111111', $resolver->caseTypeFor(path: 'createBezwaar'));
		$this->assertCount(1, $this->queries);
		$this->assertSame(['register' => 3, 'schema' => 7], $this->queries[0]['@self']);
	}//end testTheShippedCaseTypeIsTheDefault()

	/**
	 * The shipped default names case types the shipped register really seeds.
	 *
	 * A default pointing at an identifier no seed carries would leave both
	 * portal actions out on every fresh install, and every double would pass.
	 *
	 * @return void
	 */
	public function testTheShippedDefaultNamesSeededCaseTypes(): void {
		$seeded = [];
		foreach ((new RealSchemaValidator())->objects as $object) {
			if (($object['@self']['schema'] ?? null) === 'caseType') {
				$seeded[] = (string)($object['identifier'] ?? '');
			}
		}

		$shipped = json_decode(IntakeCaseTypes::SHIPPED, true);
		$this->assertSame(['createBezwaar', 'createKlacht'], array_keys($shipped));
		foreach ($shipped as $path => $identifier) {
			$this->assertContains($identifier, $seeded, 'The case type ' . $path . ' opens is seeded.');
		}
	}//end testTheShippedDefaultNamesSeededCaseTypes()

	/**
	 * An instance points a path at its own case type; a uuid is taken as it is.
	 *
	 * @return void
	 */
	public function testAConfiguredCaseTypeWins(): void {
		$resolver = $this->resolver(
			settings: [IntakeCaseTypes::SETTING => '{"createKlacht": "cccccccc-2222-4222-a222-222222222222"}'],
			byIdentity: []
		);

		$this->assertSame('cccccccc-2222-4222-a222-222222222222', $resolver->caseTypeFor(path: 'createKlacht'));
		$this->assertSame([], $this->queries);
	}//end testAConfiguredCaseTypeWins()

	/**
	 * The setting is the whole list: a path it leaves out or empties opens nothing, a new one is taken.
	 *
	 * @return void
	 */
	public function testTheSettingIsTheWholeList(): void {
		$resolver = $this->resolver(
			settings: [IntakeCaseTypes::SETTING => '{"createKlacht": "", "createAanvraag": "dddddddd-3333-4333-a333-333333333333"}'],
			byIdentity: ['bezwaar' => 'aaaaaaaa-1111-4111-a111-111111111111']
		);

		$this->assertSame(['createAanvraag' => 'dddddddd-3333-4333-a333-333333333333'], $resolver->caseTypes());
		$this->assertSame('', $resolver->caseTypeFor(path: 'createBezwaar'));
		$this->assertSame([], $this->queries);
	}//end testTheSettingIsTheWholeList()

	/**
	 * A reference nothing answers to is the empty string, and it is logged.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeIsEmptyAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '{"createKlacht": "gone"}'], byIdentity: [], logger: $logger);

		$this->assertSame('', $resolver->caseTypeFor(path: 'createKlacht'));
	}//end testAnUnknownCaseTypeIsEmptyAndLogged()

	/**
	 * A setting that is not a JSON object opens nothing, and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingOpensNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => 'bezwaar'], byIdentity: ['bezwaar' => 'x'], logger: $logger);

		$this->assertSame([], $resolver->caseTypes());
	}//end testAnUnreadableSettingOpensNothing()
}//end class
