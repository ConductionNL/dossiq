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

	/**
	 * A contested decision that cannot be established is refused as unverifiable, not cleared.
	 *
	 * @return void
	 */
	public function testAnUnestablishedContestedDecisionRefusesThePanel(): void {
		$objects = [
			'bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']],
			'decision' => ['dec-1' => ['id' => 'dec-1', 'createdBy' => 'alice']],
		];
		$worlds = [
			'no objection record for the case' => [],
			'an objection without a contested decision' => [['id' => 'obj-1', 'case' => 'case-1']],
			'a contested decision that does not resolve' => [['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-404']],
		];
		foreach ($worlds as $name => $objections) {
			$observed = $this->observe(world: ['objects' => $objects, 'objections' => $objections, 'panel' => ['alice', 'bob']]);
			$this->assertSame(
				expected: ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'],
				actual: $observed['verdict'],
				message: $name
			);
		}
	}//end testAnUnestablishedContestedDecisionRefusesThePanel()

	/**
	 * Every recorded author counts: decidedBy, the owner, createdBy and steller each make a panel member a conflict.
	 *
	 * @return void
	 */
	public function testEveryRecordedAuthorIsAConflict(): void {
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		$verdict = function (array $decision) use ($objection): array {
			$objects = ['bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']], 'decision' => ['dec-1' => ['id' => 'dec-1'] + $decision]];
			return $this->observe(world: ['objects' => $objects, 'objections' => [$objection], 'panel' => ['alice', 'bob']])['verdict'];
		};

		$this->assertTrue(condition: $verdict(['decidedBy' => 'eve'])['ok'], message: 'only decidedBy, not on the panel: independent');
		$this->assertSame(expected: 'alice', actual: $verdict(['decidedBy' => 'alice'])['member'], message: 'decidedBy on the panel');
		$hidden = $verdict(['decidedBy' => 'alice', '@self' => ['owner' => '']]);
		$this->assertSame(expected: 'alice', actual: $hidden['member'], message: 'an empty owner does not hide decidedBy');
		$owner = $verdict(['decidedBy' => 'eve', '@self' => ['owner' => 'bob']]);
		$this->assertSame(expected: 'bob', actual: $owner['member'], message: 'an owner on the panel who is not decidedBy');
		$createdBy = $verdict(['decidedBy' => 'eve', '@self' => ['owner' => 'carol'], 'createdBy' => 'bob']);
		$this->assertSame(expected: 'bob', actual: $createdBy['member'], message: 'createdBy on the panel');
		$steller = $verdict(['decidedBy' => 'eve', '@self' => ['owner' => 'carol'], 'createdBy' => 'dave', 'steller' => 'bob']);
		$this->assertSame(expected: 'bob', actual: $steller['member'], message: 'steller on the panel');
		$clear = $verdict(['decidedBy' => 'eve', '@self' => ['owner' => 'carol'], 'createdBy' => 'dave', 'steller' => 'frank']);
		$this->assertTrue(condition: $clear['ok'], message: 'no recorded author on the panel: independent');

		$objects = [
			'bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']],
			'decision' => ['dec-1' => ['id' => 'dec-1', '@self' => ['owner' => ''], 'decidedBy' => 'eve']],
		];
		$blank = $this->observe(world: ['objects' => $objects, 'objections' => [$objection], 'panel' => ['', 'alice']])['verdict'];
		$this->assertTrue(condition: $blank['ok'], message: 'an empty owner is no author, so a blank panel member is not matched');
		$emptyOnly = $verdict(['@self' => ['owner' => '']]);
		$this->assertSame(expected: [false, null], actual: [$emptyOnly['ok'], $emptyOnly['member']], message: 'only an empty owner: unverifiable');
	}//end testEveryRecordedAuthorIsAConflict()

	/**
	 * Authors and panel members are compared trimmed; blanks are neither an author nor a member.
	 *
	 * @return void
	 */
	public function testAuthorsAndMembersAreComparedTrimmed(): void {
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		$verdict = function (array $decision, array $panel) use ($objection): array {
			$objects = ['bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']], 'decision' => ['dec-1' => ['id' => 'dec-1'] + $decision]];
			return $this->observe(world: ['objects' => $objects, 'objections' => [$objection], 'panel' => $panel])['verdict'];
		};
		$unverifiable = ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'];

		$this->assertSame(expected: $unverifiable, actual: $verdict(['decidedBy' => ' '], ['alice', 'bob']), message: 'a blank author is no author');
		$this->assertSame(expected: 'alice', actual: $verdict(['decidedBy' => 'alice '], ['alice', 'bob'])['member'], message: 'a padded author');
		$this->assertSame(expected: 'alice', actual: $verdict(['decidedBy' => 'alice'], [' alice', 'bob'])['member'], message: 'a padded member');
		$blankPair = $verdict(['decidedBy' => ' '], [' ', 'bob']);
		$this->assertSame(expected: $unverifiable, actual: $blankPair, message: 'a blank author does not match a blank member');
		$this->assertSame(expected: $unverifiable, actual: $verdict(['decidedBy' => 'alice'], ['', ' ']), message: 'a blank-only panel');
	}//end testAuthorsAndMembersAreComparedTrimmed()

	/**
	 * Unicode whitespace is trimmed too, and a panel entry that is not a UID fails closed.
	 *
	 * @return void
	 */
	public function testUnicodeWhitespaceIsTrimmedAndOddEntriesFailClosed(): void {
		$objection = ['id' => 'obj-1', 'case' => 'case-1', 'contestedDecision' => 'dec-1'];
		$verdict = function (array $decision, array $panel) use ($objection): array {
			$objects = ['bezwaar' => ['bz-1' => ['id' => 'bz-1', 'case' => 'case-1']], 'decision' => ['dec-1' => ['id' => 'dec-1'] + $decision]];
			return $this->observe(world: ['objects' => $objects, 'objections' => [$objection], 'panel' => $panel])['verdict'];
		};
		$unverifiable = ['ok' => false, 'member' => null, 'reason' => 'Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)'];
		$nbsp = "\u{00A0}";

		$this->assertSame(
			expected: $unverifiable,
			actual: $verdict(['decidedBy' => $nbsp], ['alice', 'bob']),
			message: 'a non-breaking space is no author'
		);
		$this->assertSame(
			expected: 'alice',
			actual: $verdict(['decidedBy' => 'alice'.$nbsp], ['alice', 'bob'])['member'],
			message: 'an author padded with a non-breaking space'
		);
		$this->assertSame(
			expected: 'alice',
			actual: $verdict(['decidedBy' => 'alice'], ['alice'.$nbsp, 'bob'])['member'],
			message: 'a member padded with a non-breaking space'
		);
		$this->assertSame(
			expected: $unverifiable,
			actual: $verdict(['decidedBy' => 'eve'], [['alice'], 'bob']),
			message: 'a panel entry that is not a UID'
		);
		$this->assertSame(
			expected: 'alice',
			actual: $verdict(['decidedBy' => 'alice'], [null, 'alice'])['member'],
			message: 'a null entry is a blank, not an odd entry'
		);
	}//end testUnicodeWhitespaceIsTrimmedAndOddEntriesFailClosed()
}//end class
