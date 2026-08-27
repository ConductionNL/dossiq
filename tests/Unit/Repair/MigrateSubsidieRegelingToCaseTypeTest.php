<?php

/**
 * MigrateSubsidieRegelingToCaseType Unit Tests
 *
 * WHY THESE EXIST. The e2e specs for this change cannot cover the migration in
 * CI: it is a post-migration repair step, CI installs rather than upgrades, and
 * a fresh install no longer seeds subsidieRegeling objects at all — the case
 * types CI sees are written by the seeder. Reading those green e2e runs as
 * "the migration works" would be reading them about the wrong writer.
 *
 * So the migration's own behaviour is covered here. What it can get silently
 * wrong is the MAPPING: a value that survives the move but loses its meaning
 * looks correct in every list and detail view.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/subsidieregeling-is-a-casetype/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateSubsidieRegelingToCaseType;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\MigrateSubsidieRegelingToCaseType
 */
class MigrateSubsidieRegelingToCaseTypeTest extends TestCase {
	/**
	 * Every payload the step asked to save, in order.
	 *
	 * @var array<int, array{payload: array<string, mixed>, schema: string}>
	 */
	private array $saved = [];

	/**
	 * Case types the store already holds, keyed by title.
	 *
	 * @var array<int, string>
	 */
	private array $existingCaseTypes = [];

	/**
	 * Build the step over a recording object store.
	 *
	 * The store is a real recorder rather than a mock returning fixed values:
	 * these tests are about WHAT gets written, so the written thing has to be
	 * observable. A mock that accepted any payload would let a mapping error
	 * pass unnoticed, which is the exact failure being guarded against.
	 *
	 * @param array<int, array<string, mixed>> $schemes The stored subsidieRegelingen.
	 * @param array<string, string>            $config  App config overrides.
	 *
	 * @return array{0: MigrateSubsidieRegelingToCaseType, 1: IOutput} Step and output.
	 */
	private function newStep(array $schemes, array $config = []): array {
		$config = array_merge(
			[
				'register' => '1',
				'subsidie_regeling_schema' => '2',
				'case_type_schema' => '3',
				'property_definition_schema' => '4',
			],
			$config
		);

		$test = $this;
		$objectService = new class ($test, $schemes) {
			/**
			 * @param MigrateSubsidieRegelingToCaseTypeTest $test    The test.
			 * @param array<int, array<string, mixed>>      $schemes The schemes.
			 */
			public function __construct(
				private $test,
				private array $schemes,
			) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				$filters = ($config['filters'] ?? []);
				$schema = (string) ($filters['schema'] ?? '');

				// The case-type existence probe, scoped by title.
				if ($schema === '3') {
					$title = (string) ($filters['title'] ?? '');
					return $this->test->existingCaseTypesWithTitle($title);
				}

				if ($schema === '2') {
					return $this->schemes;
				}

				return [];
			}

			/**
			 * @param array<string, mixed> $payload  The object.
			 * @param array<string, mixed> $extend   Unused.
			 * @param string               $register Register id.
			 * @param string               $schema   Schema id.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $payload, array $extend, string $register, string $schema): array {
				$this->test->record(payload: $payload, schema: $schema);

				return ($payload + ['id' => 'new-' . count($payload)]);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$step = new MigrateSubsidieRegelingToCaseType(
			settingsService: $settings,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$step, $this->createMock(IOutput::class)];
	}

	/**
	 * Record a save, called by the recording store.
	 *
	 * @param array<string, mixed> $payload The object.
	 * @param string               $schema  Schema id.
	 *
	 * @return void
	 */
	public function record(array $payload, string $schema): void {
		$this->saved[] = ['payload' => $payload, 'schema' => $schema];
	}

	/**
	 * Answer the case-type existence probe.
	 *
	 * @param string $title The title probed for.
	 *
	 * @return array<int, array<string, string>> Matching rows.
	 */
	public function existingCaseTypesWithTitle(string $title): array {
		if (in_array($title, $this->existingCaseTypes, true) === true) {
			return [['title' => $title]];
		}

		return [];
	}

	/**
	 * The payloads written to the case-type schema.
	 *
	 * @return array<int, array<string, mixed>> Case type payloads.
	 */
	private function caseTypes(): array {
		return array_values(
			array_map(
				static fn (array $row): array => $row['payload'],
				array_filter($this->saved, static fn (array $row): bool => $row['schema'] === '3')
			)
		);
	}

	/**
	 * The payloads written to the property-definition schema.
	 *
	 * @return array<int, array<string, mixed>> Property definition payloads.
	 */
	private function propertyDefinitions(): array {
		return array_values(
			array_map(
				static fn (array $row): array => $row['payload'],
				array_filter($this->saved, static fn (array $row): bool => $row['schema'] === '4')
			)
		);
	}

	/**
	 * One representative scheme.
	 *
	 * @return array<string, mixed> The scheme.
	 */
	private function scheme(): array {
		return [
			'schemeName' => 'Innovatiefonds 2026',
			'legalBasis' => 'AWB titel 4.2',
			'termStart' => '2026-01-01',
			'termEnd' => '2028-12-31',
			'plafond' => 2500000,
			'targetGroup' => 'MKB',
			'interimReportFrequency' => 'annually',
			'interimReportTermWeeks' => 22,
			'requestTermWeeks' => 13,
			'determinationTermWeeks' => 22,
			'auditorsStatementThreshold' => 125000,
		];
	}

	/**
	 * The four direct-mapped fields land under their new names.
	 *
	 * @return void
	 */
	public function testTheDirectlyMappedFieldsAreCarriedAcross(): void {
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$caseTypes = $this->caseTypes();
		$this->assertCount(1, $caseTypes);
		$this->assertSame('Innovatiefonds 2026', $caseTypes[0]['title']);
		$this->assertSame('AWB titel 4.2', $caseTypes[0]['purpose']);
		$this->assertSame('2026-01-01', $caseTypes[0]['validFrom']);
		$this->assertSame('2028-12-31', $caseTypes[0]['validUntil']);
	}

	/**
	 * A week count becomes an ISO-8601 duration, not a bare integer.
	 *
	 * THE SILENT ONE. `13` is stored happily and read as a duration by nothing:
	 * the renderer and the AWB 4:13 deadline maths both expect `P13W`. An
	 * integer survives the migration and produces wrong deadlines, with no
	 * error anywhere.
	 *
	 * @return void
	 */
	public function testWeeksBecomeAnIsoDurationNotAnInteger(): void {
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$this->assertSame('P13W', $this->caseTypes()[0]['processingDeadline']);
	}

	/**
	 * The enum property keeps its allowed values.
	 *
	 * As a plain string the VALUE survives and the CONSTRAINT does not, which
	 * is why `propertyType` gained `enum` at all. An enum with no enumValues is
	 * indistinguishable from a string.
	 *
	 * @return void
	 */
	public function testTheEnumPropertyKeepsItsAllowedValues(): void {
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$freq = array_values(
			array_filter(
				$this->propertyDefinitions(),
				static fn (array $d): bool => $d['name'] === 'interimReportFrequency'
			)
		);

		$this->assertCount(1, $freq);
		$this->assertSame('enum', $freq[0]['propertyType']);
		$this->assertContains('halfjaarlijks', $freq[0]['enumValues']);
		$this->assertContains('on_milestone', $freq[0]['enumValues']);
	}

	/**
	 * Numeric properties are typed as numbers, not left as strings.
	 *
	 * @return void
	 */
	public function testNumericPropertiesKeepANumericType(): void {
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$byName = [];
		foreach ($this->propertyDefinitions() as $definition) {
			$byName[$definition['name']] = $definition;
		}

		$this->assertSame('number', $byName['plafond']['propertyType']);
		$this->assertSame('number', $byName['auditorsStatementThreshold']['propertyType']);
		$this->assertSame('string', $byName['targetGroup']['propertyType']);
	}

	/**
	 * Every property definition is attached to the case type just created.
	 *
	 * An orphaned definition is the quiet failure here: it exists, it validates,
	 * and it belongs to nothing, so the case type silently loses its fields.
	 *
	 * @return void
	 */
	public function testEveryPropertyIsAttachedToItsCaseType(): void {
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$definitions = $this->propertyDefinitions();
		$this->assertNotEmpty($definitions);
		foreach ($definitions as $definition) {
			$this->assertNotSame('', (string) ($definition['caseType'] ?? ''));
		}
	}

	/**
	 * Re-running converts nothing twice.
	 *
	 * An upgrade can be re-run at any time, and a second pass that duplicated
	 * every case type would be discovered by a human noticing doubles in a
	 * list, not by anything failing.
	 *
	 * @return void
	 */
	public function testAnAlreadyMigratedSchemeIsNotConvertedAgain(): void {
		$this->existingCaseTypes = ['Innovatiefonds 2026'];
		[$step, $output] = $this->newStep(schemes: [$this->scheme()]);

		$step->run($output);

		$this->assertSame([], $this->caseTypes());
		$this->assertSame([], $this->propertyDefinitions());
	}

	/**
	 * A scheme with no name is skipped rather than made into an untitled type.
	 *
	 * @return void
	 */
	public function testASchemeWithNoNameIsSkipped(): void {
		[$step, $output] = $this->newStep(schemes: [['legalBasis' => 'AWB titel 4.2']]);

		$step->run($output);

		$this->assertSame([], $this->caseTypes());
	}

	/**
	 * An unconfigured schema id skips the migration instead of running unscoped.
	 *
	 * Unscoped, the query returns every object in the register — measured, that
	 * produced a run of warnings over objects of entirely unrelated schemas.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaSkipsRatherThanRunningUnscoped(): void {
		[$step, $output] = $this->newStep(
			schemes: [$this->scheme()],
			config: ['subsidie_regeling_schema' => '']
		);

		$step->run($output);

		$this->assertSame([], $this->saved);
	}
}
