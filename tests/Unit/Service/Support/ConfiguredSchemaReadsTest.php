<?php

/**
 * How the services read a configured schema tolerantly.
 *
 * Pins every loader that reads one configured schema of the dossier register
 * and answers an empty list when it cannot (no OpenRegister, no register, no
 * schema), before they share one reader: the exact register, schema and
 * filters each sends, and that nothing more is read or searched once the
 * read cannot go ahead.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\ComplaintAnalyticsService;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\DeelzaakService;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\HearingService;
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Each loader reads its own schema, or answers an empty list.
 *
 * @covers \OCA\Dossiq\Service\ComplaintAnalyticsService
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationRepository
 * @covers \OCA\Dossiq\Service\DeelzaakService
 * @covers \OCA\Dossiq\Service\DoorlooptijdService
 * @covers \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @covers \OCA\Dossiq\Service\HearingService
 * @covers \OCA\Dossiq\Service\Milestone\MilestoneRepository
 * @covers \OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader
 *
 * @spec openspec/specs/case-management/spec.md
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ConfiguredSchemaReadsTest extends TestCase {
	/**
	 * Each loader, its arguments, the schema key it reads and the filters it sends.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string, 3: array<string, mixed>}>
	 */
	public static function loaders(): array {
		return [
			'turnaround cases' => [DoorlooptijdService::class . '::loadCases', ['permit'], 'case_schema', ['_limit' => 1000, 'caseType' => 'permit']],
			'turnaround case types' => [DoorlooptijdService::class . '::loadCaseTypes', [], 'case_type_schema', ['_limit' => 500]],
			'mining cases' => [ProcessMiningDataLoader::class . '::loadCases', ['permit'], 'case_schema', ['_limit' => 2000, 'caseType' => 'permit']],
			'mining case types' => [ProcessMiningDataLoader::class . '::loadCaseTypes', [], 'case_type_schema', ['_limit' => 500]],
			'mining status types' => [ProcessMiningDataLoader::class . '::loadStatusTypes', [], 'status_type_schema', ['_limit' => 500]],
			'mining status records' => [ProcessMiningDataLoader::class . '::loadStatusRecords', [], 'status_record_schema', ['_limit' => 10000]],
			'milestone records' => [MilestoneRepository::class . '::findRecords', ['c-1'], 'milestone_record_schema', ['case' => 'c-1', '_limit' => 100]],
			'email templates' => [CaseEmailRepository::class . '::findTemplatesForCaseType', ['ct-1'], 'email_template_schema', ['caseType' => 'ct-1', '_limit' => 100]],
			'consultations' => [ConsultationRepository::class . '::getConsultationsForCase', ['c-1'], 'consultation_schema', ['parentCase' => 'c-1', '_limit' => 100]],
			'hearings' => [HearingService::class . '::getHearingsForComplaint', ['k-1'], 'hearing_schema', ['complaint' => 'k-1']],
			'sub-cases' => [DeelzaakService::class . '::listSubCases', ['c-1'], 'case_schema', ['parentCase' => 'c-1', '_limit' => 200]],
			'complaints in range' => [
				ComplaintAnalyticsService::class . '::fetchComplaintsInRange',
				['2026-01-01', '2026-03-31'],
				'complaint_schema',
				['ontvangstdatum>=' => '2026-01-01', 'ontvangstdatum<=' => '2026-03-31', '_limit' => 10000],
			],
		];
	}//end loaders()

	/**
	 * A configured schema is searched under the loader's filters.
	 *
	 * @param string               $loader    The loader, as Class::method.
	 * @param array<int, mixed>    $arguments Its arguments.
	 * @param string               $key       The schema key it reads.
	 * @param array<string, mixed> $filters   The filters it sends.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testAConfiguredSchemaIsSearched(string $loader, array $arguments, string $key, array $filters): void {
		$register = $this->register();
		$rows = $this->load(loader: $loader, arguments: $arguments, settings: $this->settings(service: $register, config: ['register' => 'dossiq', $key => 'things']));

		self::assertSame(expected: [[['id' => 'a', 'title' => 'A']], [['dossiq', 'things', $filters]]], actual: [$rows, $register->calls]);
	}//end testAConfiguredSchemaIsSearched()

	/**
	 * Without OpenRegister the list is empty and no configuration is read.
	 *
	 * @param string            $loader    The loader, as Class::method.
	 * @param array<int, mixed> $arguments Its arguments.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheListIsEmpty(string $loader, array $arguments): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');

		self::assertSame(expected: [], actual: $this->load(loader: $loader, arguments: $arguments, settings: $settings));
	}//end testWithoutOpenRegisterTheListIsEmpty()

	/**
	 * Without a register, or without the schema, the list is empty and nothing is searched.
	 *
	 * @param string            $loader    The loader, as Class::method.
	 * @param array<int, mixed> $arguments Its arguments.
	 * @param string            $key       The schema key it reads.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testAMissingRegisterOrSchemaGivesAnEmptyList(string $loader, array $arguments, string $key): void {
		$register = $this->register();
		$noRegister = $this->load(loader: $loader, arguments: $arguments, settings: $this->settings(service: $register, config: [$key => 'things']));
		$noSchema = $this->load(loader: $loader, arguments: $arguments, settings: $this->settings(service: $register, config: ['register' => 'dossiq']));

		self::assertSame(expected: [[], [], []], actual: [$noRegister, $noSchema, $register->calls]);
	}//end testAMissingRegisterOrSchemaGivesAnEmptyList()

	/**
	 * Sub-cases of no parent are none, and OpenRegister is not asked.
	 *
	 * @return void
	 */
	public function testSubCasesOfNoParentAreNone(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects(self::never())->method('getObjectService');

		self::assertSame(expected: [], actual: $this->load(loader: DeelzaakService::class . '::listSubCases', arguments: [''], settings: $settings));
	}//end testSubCasesOfNoParentAreNone()

	/**
	 * An object service that records each slug search and answers one row.
	 *
	 * @return object The register double.
	 */
	private function register(): object {
		return new class {
			/**
			 * The searches, as register, schema and filters.
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Record a slug search.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One row.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = [$register, $schema, $filters];

				return [['id' => 'a', 'title' => 'A']];
			}//end searchObjectsBySlug()
		};
	}//end register()

	/**
	 * Settings answering the given object service and configuration.
	 *
	 * @param object|null           $service The object service, or null.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?object $service, array $config): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return $settings;
	}//end settings()

	/**
	 * Run one loader, private or public, over the given settings.
	 *
	 * @param string            $loader    The loader, as Class::method.
	 * @param array<int, mixed> $arguments Its arguments.
	 * @param SettingsService   $settings  The settings.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function load(string $loader, array $arguments, SettingsService $settings): array {
		[$class, $method] = explode(separator: '::', string: $loader);
		$parameters = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$parameters[] = $this->argument(type: $type->getName(), settings: $settings);
		}

		$subject = new $class(...$parameters);

		return (new ReflectionMethod($subject, $method))->invoke($subject, ...$arguments);
	}//end load()

	/**
	 * The settings for a SettingsService parameter, a double for any other.
	 *
	 * @param class-string    $type     The parameter's class.
	 * @param SettingsService $settings The settings.
	 *
	 * @return object The argument.
	 */
	private function argument(string $type, SettingsService $settings): object {
		if ($type === SettingsService::class) {
			return $settings;
		}

		return $this->createMock(originalClassName: $type);
	}//end argument()
}//end class
