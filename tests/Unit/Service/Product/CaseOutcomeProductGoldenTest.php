<?php

/**
 * Golden test for issuing and changing the product a case outcome declares.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Product
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
 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Product;

use OCA\Dossiq\Service\Product\CaseOutcomeProductIssuer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every store call (and whether it ran as the system), every log line
 * and every answer of issuing and changing a product.
 *
 * @covers \OCA\Dossiq\Service\Product\CaseOutcomeProductIssuer
 * @covers \OCA\Dossiq\Service\Product\ProductOutcome
 * @covers \OCA\Dossiq\Service\Product\ProductDecision
 */
class CaseOutcomeProductGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every store call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every log line, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * A case type that issues a product.
	 *
	 * @return array<string, mixed>
	 */
	private static function issuing(): array {
		return [
			'kind' => ' parkeren-bewoner ',
			'theme' => 'parkeren',
			'titleTemplate' => 'Vergunning {{ adres }} voor {{kenteken}} {{missing}}',
			'detailsFromCase' => ['kenteken' => 'kenteken', 'adres' => 'adres', 'zone' => 'zone', 'leeg' => 'nothing', 'blank' => '', 'nested' => 'nested', 'list' => 'arr'],
			'compactFields' => ['kenteken', 'zone'],
			'issueOn' => ['approved'],
		];
	}//end issuing()

	/**
	 * A case type that changes a product.
	 *
	 * @return array<string, mixed>
	 */
	private static function changing(): array {
		return [
			'schema' => 'licence',
			'changesProduct' => ['answer' => 'permit', 'set' => ['kenteken' => 'nieuwKenteken', 'adres' => 'nieuwAdres', 'zone' => 'zone']],
			'compactFields' => ['kenteken'],
		];
	}//end changing()

	/**
	 * The world before each scenario.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function world(): array {
		return [
			'case' => [
				'c1' => [
					'id' => 'c1',
					'caseType' => 't1',
					'portalSubject' => ' subj-sanne ',
					'title' => ' Parkeren Lindelaan ',
					'zone' => ' a - 1 ',
					'nested' => ['x' => 1],
					'properties' => [
						['name' => 'kenteken', 'value' => ' gz-482 k '],
						['name' => 'adres', 'value' => 'Lindelaan 4'],
						['name' => 'nested', 'value' => 'from answers'],
						'not an answer',
						['name' => 'arr', 'value' => ['no']],
						['name' => '', 'value' => 'unnamed'],
						['name' => 'arr', 'value' => 'second'],
					],
				],
				'c2' => [
					'id' => 'c2',
					'caseType' => 't2',
					'portalSubject' => 'subj-sanne',
					'zone' => '',
					'properties' => [['name' => 'permit', 'value' => ' p1 '], ['name' => 'nieuwKenteken', 'value' => 'hx-901 b'], ['name' => 'nieuwAdres', 'value' => ' Eikenlaan 2 ']],
				],
			],
			'caseType' => [
				't1' => ['id' => 't1', 'issuesPermit' => self::issuing()],
				't2' => ['id' => 't2', 'issuesPermit' => self::changing()],
			],
			'permit' => [],
			'licence' => [
				'p1' => ['id' => 'p1', 'portalSubject' => 'subj-sanne', 'status' => 'active', 'kenteken' => 'GZ482K'],
			],
		];
	}//end world()

	/**
	 * Run one scenario and record what happened.
	 *
	 * @param array<string, mixed> $scenario `world` changes (callable), `case`, `status`, `decidedAt`.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $scenario): array {
		$this->calls = [];
		$this->logs = [];
		$world = self::world();
		($scenario['world'] ?? static function (array &$world): void {
		})($world);
		$store = $this->store(world: $world);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq'][$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$answer = (new CaseOutcomeProductIssuer(settingsService: $settings, logger: $logger))->issueFor(
			caseId: (string)$scenario['case'],
			decisionId: 'd1',
			status: (string)($scenario['status'] ?? 'approved'),
			decidedAt: ($scenario['decidedAt'] ?? '2026-10-08T14:00:00+02:00')
		);

		return ['answer' => $answer, 'calls' => $this->calls, 'logs' => $this->logs, 'products' => [$store->world['permit'], $store->world['licence']]];
	}//end observe()

	/**
	 * An in-memory object store that records each call and its system scope.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $world The objects by schema and id.
	 *
	 * @return object
	 */
	private function store(array $world): object {
		$calls = &$this->calls;
		return new class($world, $calls) {
			/** @var int How deep inside runAsSystem the store is. */
			private int $system = 0;

			/**
			 * @param array<string, array<string, array<string, mixed>>> $world The objects.
			 * @param array<int, array<string, mixed>>                   $calls The call log.
			 */
			public function __construct(public array $world, private array &$calls) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->system++;
				try {
					return $operation();
				} finally {
					$this->system--;
				}
			}

			/**
			 * @param string $call The call.
			 * @param array  $args The arguments.
			 *
			 * @return void
			 */
			private function log(string $call, array $args): void {
				$this->calls[] = ['call' => $call, 'system' => $this->system] + $args;
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws DoesNotExistException When there is none.
			 */
			public function find(string $id, string $register = '', string $schema = ''): array {
				$this->log(call: 'find', args: ['id' => $id, 'register' => $register, 'schema' => $schema]);
				if (isset($this->world[$schema][$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->world[$schema][$id];
			}

			/**
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log(call: 'search', args: ['register' => $register, 'schema' => $schema, 'filters' => $filters, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy]);
				return array_values(
					array_filter(
						($this->world[$schema] ?? []),
						static fn (array $row): bool => ($row['decision'] ?? null) === ($filters['decision'] ?? null)
					)
				);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->log(call: 'save', args: ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]);
				$id = ($uuid ?? 'new-' . count($this->world[$schema] ?? []));
				$this->world[$schema][$id] = array_merge($object, ['id' => $id]);
				return $this->world[$schema][$id];
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch): array {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$register = ($patch->target->register ?? '');
				$schema = ($patch->target->schema ?? '');
				$this->log(call: 'patch', args: ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]);
				$this->world[$schema][$objectId] = array_merge($this->world[$schema][$objectId], $data);
				return $this->world[$schema][$objectId];
			}
		};
	}//end store()

	/**
	 * Every issue and change path.
	 *
	 * @return void
	 */
	public function testGoldenIssueAndChange(): void {
		$scenarios = [
			'issue' => ['case' => 'c1'],
			'issue, no titleTemplate' => ['case' => 'c1', 'world' => static function (array &$w): void {
				unset($w['caseType']['t1']['issuesPermit']['titleTemplate']);
			}],
			'issue, blank titleTemplate and no case title' => ['case' => 'c1', 'world' => static function (array &$w): void {
				$w['caseType']['t1']['issuesPermit']['titleTemplate'] = '  ';
				unset($w['case']['c1']['title']);
			}],
			'issue, own schema, no statics, no details' => ['case' => 'c1', 'decidedAt' => '2026-01-02', 'world' => static function (array &$w): void {
				$w['caseType']['t1']['issuesPermit'] = ['schema' => ' licence ', 'theme' => '  ', 'titleTemplate' => '{{missing}}'];
			}],
			'issue, already issued for the decision' => ['case' => 'c1', 'world' => static function (array &$w): void {
				$w['permit']['old'] = ['id' => 'old', 'decision' => 'd1', 'title' => 'earlier'];
				$w['permit']['other'] = ['id' => 'other', 'decision' => 'd9'];
			}],
			'issue, issued for another decision only' => ['case' => 'c1', 'world' => static function (array &$w): void {
				$w['permit']['other'] = ['id' => 'other', 'decision' => 'd9'];
			}],
			'issue, no holder' => ['case' => 'c1', 'world' => static function (array &$w): void {
				$w['case']['c1']['portalSubject'] = '   ';
			}],
			'issue, holder missing' => ['case' => 'c1', 'world' => static function (array &$w): void {
				unset($w['case']['c1']['portalSubject'], $w['case']['c1']['id']);
			}],
			'issue, case without id' => ['case' => 'c1', 'world' => static function (array &$w): void {
				unset($w['case']['c1']['id']);
			}],
			'change' => ['case' => 'c2'],
			'change, default schema' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['caseType']['t2']['issuesPermit']['schema']);
				$w['permit']['p1'] = $w['licence']['p1'];
			}],
			'change, product without status' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['licence']['p1']['status']);
			}],
			'change, product of somebody else' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['licence']['p1']['portalSubject'] = 'subj-other';
			}],
			'change, case without holder' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['case']['c2']['portalSubject'], $w['case']['c2']['id']);
			}],
			'change, product without holder' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['licence']['p1']['portalSubject']);
			}],
			'change, both without holder' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['licence']['p1']['portalSubject'], $w['case']['c2']['portalSubject']);
			}],
			'change, product revoked' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['licence']['p1']['status'] = 'revoked';
			}],
			'change, product missing' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['licence']['p1']);
			}],
			'change, no answer named' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['caseType']['t2']['issuesPermit']['changesProduct']['answer']);
			}],
			'change, nothing to set' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['caseType']['t2']['issuesPermit']['changesProduct']['set'] = ['zone' => 'zone', 'x' => 'nothing'];
			}],
			'change, no set' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['caseType']['t2']['issuesPermit']['changesProduct']['set']);
			}],
			'change, holder only in the answers' => ['case' => 'c2', 'world' => static function (array &$w): void {
				unset($w['case']['c2']['portalSubject']);
				$w['case']['c2']['properties'][] = ['name' => 'portalSubject', 'value' => 'subj-sanne'];
			}],
			'change, holder differs by case and space' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['case']['c2']['portalSubject'] = ' SUBJ-SANNE ';
			}],
			'change, product expired' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['licence']['p1']['status'] = 'expired';
			}],
			'change, answer names another schema' => ['case' => 'c2', 'world' => static function (array &$w): void {
				$w['case']['c2']['properties'][] = ['name' => 'schema', 'value' => 'permit'];
				$w['permit']['p1'] = ['id' => 'p1', 'portalSubject' => 'subj-sanne', 'status' => 'active', 'kenteken' => 'OLD1'];
			}],
			'issue, answer names another holder' => ['case' => 'c1', 'world' => static function (array &$w): void {
				$w['case']['c1']['properties'][] = ['name' => 'holder', 'value' => 'subj-mallory'];
				$w['case']['c1']['properties'][] = ['name' => 'portalSubject', 'value' => 'subj-mallory'];
			}],
			'issue, holder only in the answers' => ['case' => 'c1', 'world' => static function (array &$w): void {
				unset($w['case']['c1']['portalSubject']);
				$w['case']['c1']['properties'][] = ['name' => 'portalSubject', 'value' => 'subj-mallory'];
			}],
			'not declared outcome' => ['case' => 'c1', 'status' => 'rejected'],
			'declared outcome in other case' => ['case' => 'c1', 'status' => ' APPROVED '],
		];

		$observed = [];
		foreach ($scenarios as $name => $scenario) {
			$observed[$name] = $this->observe(scenario: $scenario);
		}

		$this->assertMatchesGolden('case-outcome-product', $observed);
	}//end testGoldenIssueAndChange()
}//end class
