<?php

/**
 * PackageInstaller write golden test
 *
 * Pins what installing a valid package reads, writes, records, takes back,
 * logs and answers once it is past validation: the ledger check, the
 * references a package needs, the write, the ledger record and its roll-back,
 * and the fill, refresh and settings that follow.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageCapabilities;
use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\CaseDefinition\PackageSchemaReferences;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * Golden test for PackageInstaller::install() and installOverLocalChanges().
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageInstaller
 */
class PackageInstallerWriteGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any collaborator received, by parameter name.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A double whose listed methods record their call and answer as configured.
	 *
	 * Arguments left at their declared default are not recorded.
	 *
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, or a closure over the named args.
	 *
	 * @return object The double.
	 */
	private function double(string $class, array $behaviour): object {
		$mock = $this->createMock(originalClassName: $class);
		foreach ($behaviour as $method => $answer) {
			$params = (new ReflectionMethod($class, $method))->getParameters();
			$mock->method($method)->willReturnCallback(
				function (mixed ...$args) use ($class, $method, $params, $answer): mixed {
					$named = [];
					foreach ($args as $index => $value) {
						$param = $params[$index];
						if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $value) {
							continue;
						}

						$named[$param->getName()] = $value;
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, $named];
					if ($answer instanceof \Closure) {
						return $answer(...$named);
					}

					return $answer;
				}
			);
		}

		return $mock;
	}//end double()

	/**
	 * One row as the writer reports it.
	 *
	 * @param string $id        The row id.
	 * @param string $schemaKey The settings key of its schema; '' leaves the key out.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(string $id, string $schemaKey): array {
		$row = ['schema' => 's-' . $id, 'id' => $id, 'row' => ['name' => strtoupper($id)]];
		if ($schemaKey !== '') {
			$row['schemaKey'] = $schemaKey;
		}

		return $row;
	}//end row()

	/**
	 * The package every world starts from.
	 *
	 * @return array<string, mixed> The manifest, files and writer answer.
	 */
	private static function base(): array {
		return [
			'manifest' => ['version' => '1.2.0', 'parts' => ['case-type', 'flows', 'seed', 'settings']],
			'files' => [
				'case-type.json' => (string)json_encode(
					[
						'caseType' => ['name' => 'Fixture'],
						'propertyDefinitions' => [['name' => 'p1'], ['name' => 'p2']],
						'statusTypes' => [],
						'roleTypes' => 'one-role',
					]
				),
				'settings.json' => '{"new_key":"value","flag":true,"off":false,"count":3,"present_key":"theirs","ref":"app:slug"}',
			],
			'rowsUnder' => [
				'flows/' => [['name' => 'flow-1']],
				'seed/' => [['name' => 'seed-1'], ['name' => 'seed-2']],
			],
			'written' => [
				'status' => 'success',
				'message' => 'Wrote the package.',
				'written' => [self::row(id: 'w1', schemaKey: 'case_type_schema'), self::row(id: 'w2', schemaKey: '')],
				'adopted' => [self::row(id: 'a1', schemaKey: 'case_type_schema'), self::row(id: 'a2', schemaKey: 'status_type_schema')],
				'skipped' => [self::row(id: 's1', schemaKey: 'role_type_schema')],
				'held' => [self::row(id: 'h1', schemaKey: 'role_type_schema')],
			],
		];
	}//end base()

	/**
	 * Install one package against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator.
	 *
	 * @return array<string, mixed> The answer, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$world += self::base();
		$world += ['stateSets' => ['a1' => 'pkg']];
		$logger = new class extends AbstractLogger {
			/**
			 * The lines logged.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$installer = new PackageInstaller(
			packages: $this->double(
				class: PackageDirectory::class,
				behaviour: [
					'read' => ['id' => 'pkg', 'files' => $world['files'], 'digest' => 'digest-1'],
					'rowsUnder' => static fn (mixed ...$named): array => ($world['rowsUnder'][$named['dir']] ?? []),
				]
			),
			validator: $this->double(
				class: PackageValidator::class,
				behaviour: ['validateShipped' => ['valid' => true, 'manifest' => $world['manifest'], 'errors' => []]]
			),
			capabilities: $this->double(class: PackageCapabilities::class, behaviour: ['missing' => ($world['capabilities'] ?? [])]),
			writer: $this->double(
				class: PackageWriter::class,
				behaviour: [
					'writePackage' => $world['written'],
					'undo' => null,
					'fillAdopted' => ($world['filled'] ?? [self::row(id: 'a2', schemaKey: 'status_type_schema')]),
				]
			),
			ledger: $this->double(
				class: ShippedConfigurationService::class,
				behaviour: [
					'isPackageCurrent' => false,
					'ledgerAvailable' => ($world['ledger'] ?? true),
					'stamp' => static fn (mixed ...$named): bool => in_array($named['targetObject'], ($world['stampFails'] ?? []), true) === false,
					'stateOf' => static fn (mixed ...$named): array => ['set' => ($world['stateSets'][$named['targetObject']] ?? 'other')],
					'recordPackage' => ($world['recordPackage'] ?? true),
					'refreshRows' => ($world['refresh'] ?? ['refreshed' => ['h1'], 'kept' => ['h2', 'h3']]),
				]
			),
			appConfig: $this->double(class: IAppConfig::class, behaviour: ['getKeys' => ['present_key'], 'setValueString' => true]),
			logger: $logger,
			references: $this->double(
				class: PackageSchemaReferences::class,
				behaviour: [
					'resolve' => static function (mixed ...$named) use ($world): array {
						$settings = $named['settings'];
						if (isset($settings['ref']) === true) {
							$settings['ref'] = 'schema-7';
						}

						return ($world['resolve'] ?? ['settings' => $settings, 'missing' => [], 'reasons' => []]);
					},
					'seedGroup' => static fn (mixed ...$named): array => (
						$world['seedGroups'][$named['seed']['name']] ?? ['schemaKey' => 'seed_schema', 'rows' => [$named['seed']]]
					),
				]
			),
		);

		if (($world['over'] ?? false) === true) {
			$answer = $installer->installOverLocalChanges(id: 'pkg');
		} else {
			$answer = $installer->install(id: 'pkg');
		}

		return ['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines];
	}//end observe()

	/**
	 * Every path through writing a valid package, across its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenWrite(): void {
		$missingSeed = ['schemaKey' => 'seed_schema', 'rows' => [['name' => 'x']], 'missing' => 'other:slug', 'reason' => 'other has no slug'];
		$worlds = [
			'installed' => [],
			'installed over local changes' => ['over' => true],
			'ledger not configured' => ['ledger' => false],
			'a setting names a missing schema' => [
				'resolve' => ['settings' => [], 'missing' => ['app:slug', 'app:other'], 'reasons' => ['app has no slug', 'app has no other']],
			],
			'a seed file names a missing schema' => ['seedGroups' => ['seed-2' => $missingSeed]],
			'settings and seeds miss the same schema' => [
				'resolve' => ['settings' => [], 'missing' => ['other:slug'], 'reasons' => ['setting misses it']],
				'seedGroups' => ['seed-1' => $missingSeed, 'seed-2' => $missingSeed],
			],
			'a missing seed group without rows' => [
				'seedGroups' => ['seed-1' => ['schemaKey' => 'seed_schema', 'rows' => [], 'missing' => 'x:y', 'reason' => 'x has no y']],
			],
			'the write fails' => ['written' => ['status' => 'error', 'message' => 'The store refused a row.', 'written' => []]],
			'a written row is not recorded' => ['stampFails' => ['w2']],
			'the first written row is not recorded' => ['stampFails' => ['w1']],
			'an adopted row is not recorded' => ['stampFails' => ['a2']],
			'the package is not recorded' => ['recordPackage' => false],
			'no settings part' => ['manifest' => ['version' => '1.2.0', 'parts' => ['case-type', 'flows', 'seed']]],
			'no parts' => ['manifest' => ['version' => '2.0.0']],
			'settings a JSON scalar' => ['files' => ['settings.json' => '"text"'], 'manifest' => ['version' => '1.0.0', 'parts' => ['settings']]],
			'settings not JSON' => ['files' => ['settings.json' => '{nope'], 'manifest' => ['version' => '1.0.0', 'parts' => ['settings']]],
			'nothing adopted, filled or kept' => [
				'written' => [
					'status' => 'success',
					'message' => 'Wrote it.',
					'written' => [self::row(id: 'w1', schemaKey: 'case_type_schema')],
					'adopted' => [],
					'skipped' => [],
					'held' => [],
				],
				'filled' => [],
				'refresh' => ['refreshed' => [], 'kept' => []],
				'over' => true,
			],
			'empty flows and seeds' => ['rowsUnder' => []],
			'requires named and implied' => [
				'manifest' => ['version' => '1.2.0', 'requires' => ['openregister', 7, 'openregister'], 'parts' => ['case-type', 'unknown-part', 'flows']],
			],
			'a capability missing' => ['capabilities' => ['openregister', 'flows']],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'package-installer-write', value: $observed);
	}//end testGoldenWrite()
}//end class
