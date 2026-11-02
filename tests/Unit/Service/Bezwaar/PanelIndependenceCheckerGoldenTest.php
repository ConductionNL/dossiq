<?php

/**
 * PanelIndependenceChecker golden test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for PanelIndependenceChecker::check(): the verdict, every store and settings call, and the logs.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker
 */
class PanelIndependenceCheckerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Run one check in a world and observe it.
	 *
	 * @param array<string, mixed> $world The settings, the stored objects, the objection id and the panel.
	 *
	 * @return array<string, mixed> The verdict, the calls in order and the log lines.
	 */
	private function observe(array $world): array {
		$calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$store = new class($world, $calls) {
			/**
			 * @param array<string, mixed> $world The world.
			 * @param array<int, mixed>    $calls The call log.
			 */
			public function __construct(private array $world, private array &$calls) {
			}

			/**
			 * Answer one stored object by schema and id.
			 *
			 * @param int|string $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return mixed The object.
			 */
			public function find(int|string $id, int|string $register = '', int|string $schema = ''): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				$found = ($this->world['objects'][$schema][$id] ?? 'missing');
				if ($found === 'missing') {
					throw new DoesNotExistException('not found');
				}

				if ($found === 'throw') {
					throw new RuntimeException('store down');
				}

				return $found;
			}

			/**
			 * Answer the objections on a case, slug path.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return mixed The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): mixed {
				$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];
				return $this->rows();
			}

			/**
			 * Answer the objections on a case, numeric path.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed The rows.
			 */
			public function searchObjects(array $query = []): mixed {
				$this->calls[] = ['searchObjects', $query];
				return $this->rows();
			}

			/**
			 * The objection rows of the world.
			 *
			 * @return mixed The rows.
			 */
			private function rows(): mixed {
				if (($this->world['objections'] ?? null) === 'throw') {
					throw new RuntimeException('search down');
				}

				return ($this->world['objections'] ?? []);
			}
		};

		$config = ($world['config'] ?? []) + [
			'register' => 'dossiq',
			'bezwaar_schema' => 'bezwaar',
			'objection_schema' => 'objection',
			'decision_schema' => 'decision',
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use (&$calls, $world, $store): ?object {
				$calls[] = ['getObjectService'];
				if (($world['noStore'] ?? false) === true) {
					return null;
				}

				return $store;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use (&$calls, $config): string {
				$calls[] = ['getConfigValue', $key];
				return ($config[$key] ?? $default);
			}
		);

		$checker = new PanelIndependenceChecker(settingsService: $settings, logger: $logger);
		$verdict = $checker->check(objectionId: ($world['objectionId'] ?? 'bz-1'), panel: ($world['panel'] ?? ['alice', 'bob']));

		return ['verdict' => $verdict, 'calls' => $calls, 'logs' => $logger->lines];
	}//end observe()

	/**
	 * Every path through check().
	 *
	 * @return void
	 */
	public function testGoldenCheck(): void {
		$objects = [
			'bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']],
			'decision' => ['dec-1' => ['id' => 'dec-1', '@self' => ['owner' => 'bob'], 'createdBy' => 'carol', 'steller' => 'dave']],
		];
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		$base = ['objects' => $objects, 'objections' => [$objection]];
		$decision = static fn (array $row): array => ['objects' => ['decision' => ['dec-1' => $row]] + $objects, 'objections' => [$objection]];
		$jsonDecision = new class() implements \JsonSerializable {
			/**
			 * The decision.
			 *
			 * @return mixed The row.
			 */
			public function jsonSerialize(): mixed {
				return ['id' => 'dec-1', 'createdBy' => 'alice'];
			}
		};
		$worlds = [
			'owner on the panel' => $base,
			'empty objection id' => ['objectionId' => ''] + $base,
			'empty panel' => ['panel' => []] + $base,
			'no store' => ['noStore' => true] + $base,
			'no objection schema' => ['config' => ['objection_schema' => '']] + $base,
			'no decision schema' => ['config' => ['decision_schema' => '']] + $base,
			'no register' => ['config' => ['register' => '']] + $base,
			'no legacy schema, the id is the case' => ['config' => ['bezwaar_schema' => ''], 'objectionId' => 'case-1'] + $base,
			'legacy object missing' => ['objectionId' => 'bz-404'] + $base,
			'legacy object without a case' => ['objects' => ['bezwaar' => ['bz-1' => ['id' => 'bz-1']]] + $objects, 'objections' => [$objection]],
			'legacy lookup throws' => ['objects' => ['bezwaar' => ['bz-1' => 'throw']] + $objects, 'objections' => [$objection]],
			'legacy object not an array' => ['objects' => ['bezwaar' => ['bz-1' => 'text']] + $objects, 'objections' => [$objection]],
			'no objection' => ['objects' => $objects, 'objections' => []],
			'objection search throws' => ['objects' => $objects, 'objections' => 'throw'],
			'first objection is used' => ['objects' => $objects, 'objections' => [['contestedDecision' => ''], $objection]],
			'objection without a contested decision' => ['objects' => $objects, 'objections' => [['case' => 'case-1']]],
			'contested decision missing' => ['objects' => $objects, 'objections' => [['contestedDecision' => 'dec-404']]],
			'decision lookup throws' => ['objects' => ['decision' => ['dec-1' => 'throw']] + $objects, 'objections' => [$objection]],
			'createdBy when no owner' => $decision(['createdBy' => 'alice', 'steller' => 'bob']),
			'steller when no owner or createdBy' => $decision(['steller' => 'alice']),
			'no author' => $decision(['id' => 'dec-1']),
			'no author, a blank member on the panel' => ['panel' => ['', 'alice']] + $decision(['id' => 'dec-1']),
			'author not on the panel' => $decision(['createdBy' => 'eve']),
			'json decision' => ['objects' => ['decision' => ['dec-1' => $jsonDecision]] + $objects, 'objections' => [$objection]],
			'first conflicting member named' => ['panel' => ['bob', 'bob', 'alice']] + $base,
			'numeric member' => ['panel' => [7, 'alice']] + $decision(['createdBy' => '7']),
			'numeric register and schemas' => [
				'config' => ['register' => '3', 'bezwaar_schema' => '', 'objection_schema' => '4', 'decision_schema' => '5'],
				'objectionId' => 'case-1',
				'objects' => ['5' => $objects['decision']],
			] + $base,
			'objection row as an object' => ['objects' => $objects, 'objections' => [(object)$objection]],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'panel-independence-check', value: $observed);
	}//end testGoldenCheck()

	/**
	 * A panel whose check cannot read the store is refused, not cleared.
	 *
	 * @return void
	 */
	public function testAStoreReadThatThrowsRefusesThePanel(): void {
		$objects = [
			'bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']],
			'decision' => ['dec-1' => ['id' => 'dec-1', 'createdBy' => 'alice']],
		];
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		$worlds = [
			'legacy lookup throws' => ['objects' => ['bezwaar' => ['bz-1' => 'throw']] + $objects, 'objections' => [$objection]],
			'objection search throws' => ['objects' => $objects, 'objections' => 'throw'],
			'decision lookup throws' => ['objects' => ['decision' => ['dec-1' => 'throw']] + $objects, 'objections' => [$objection]],
		];

		foreach ($worlds as $name => $world) {
			$observed = $this->observe(world: $world);
			$this->assertSame(
				expected: ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'],
				actual: $observed['verdict'],
				message: $name
			);
			$this->assertSame(expected: 'error', actual: $observed['logs'][0][0], message: $name);
		}
	}//end testAStoreReadThatThrowsRefusesThePanel()

	/**
	 * A panel whose objection or decision schema is not configured is refused, not cleared.
	 *
	 * @return void
	 */
	public function testMissingSchemasRefuseThePanel(): void {
		$objects = ['decision' => ['dec-1' => ['id' => 'dec-1', 'createdBy' => 'alice']]];
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		foreach (['objection_schema', 'decision_schema'] as $key) {
			$observed = $this->observe(world: ['config' => [$key => ''], 'objects' => $objects, 'objections' => [$objection]]);
			$this->assertSame(
				expected: ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'],
				actual: $observed['verdict'],
				message: $key
			);
		}
	}//end testMissingSchemasRefuseThePanel()

	/**
	 * A request without an objection is refused as unverifiable, not cleared.
	 *
	 * @return void
	 */
	public function testAnEmptyObjectionRefusesThePanel(): void {
		$objects = ['decision' => ['dec-1' => ['id' => 'dec-1', 'createdBy' => 'alice']]];
		$observed = $this->observe(world: ['objectionId' => '', 'objects' => $objects, 'objections' => []]);

		$this->assertSame(
			expected: ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'],
			actual: $observed['verdict']
		);
		$this->assertSame(expected: [], actual: $observed['calls'], message: 'an empty objection reads nothing');
	}//end testAnEmptyObjectionRefusesThePanel()

	/**
	 * A contested decision without a recorded author is refused as unverifiable, not cleared.
	 *
	 * @return void
	 */
	public function testADecisionWithoutAnAuthorRefusesThePanel(): void {
		$objects = ['bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']], 'decision' => ['dec-1' => ['id' => 'dec-1']]];
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		foreach (['a panel' => ['alice', 'bob'], 'a blank member on the panel' => ['', 'alice']] as $name => $panel) {
			$observed = $this->observe(world: ['objects' => $objects, 'objections' => [$objection], 'panel' => $panel]);
			$this->assertSame(
				expected: ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'],
				actual: $observed['verdict'],
				message: $name
			);
		}
	}//end testADecisionWithoutAnAuthorRefusesThePanel()
}//end class
