<?php

/**
 * Golden test for TermInstanceStore: what each read and write answers, asks and
 * logs, configured, unconfigured and failing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class TermInstanceStoreGoldenTest extends TestCase {

	private object $objects;

	private array $logged = [];

	private array $config = ['register' => 'dossiq', 'termijn_instance_schema' => 'instances', 'other_schema' => 'other'];

	private bool $noService = false;

	protected function setUp(): void {
		$this->logged  = [];
		$this->objects = new class {
			public array $calls = [];

			public bool $fail = false;

			public array $rows = [];

			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->fail === true) {
					throw new RuntimeException('find down');
				}

				return ['id' => $id];
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				if ($this->fail === true) {
					throw new RuntimeException('search down');
				}

				return $this->rows;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): array {
				$this->calls[] = ['save', $register, $schema, $object];
				if ($this->fail === true) {
					throw new RuntimeException('save down');
				}

				return ['id' => 'saved'] + $object;
			}
		};
	}//end setUp()

	public function testConfiguredReadsAndWrites(): void {
		$this->objects->rows = [
			['id' => 'a', 'startDate' => '2026-01-02'],
			['id' => 'b'],
			['id' => 'c', 'startDate' => '2026-03-01'],
		];
		$store = $this->store();

		$this->assertSame('t-1', $store->read('t-1')['id']);
		$this->assertSame(['c', 'a', 'b'], array_column($store->allForCase('case-1'), 'id'));
		$this->assertSame('c', $store->latestForCase('case-1')['id']);
		$this->assertSame('saved', $store->save('other_schema', ['x' => 1])['id']);
		$this->assertSame(
			[
				['search', 'dossiq', 'instances', ['case' => 'case-1']],
				['search', 'dossiq', 'instances', ['case' => 'case-1']],
				['save', 'dossiq', 'other', ['x' => 1]],
			],
			array_values(array_filter($this->objects->calls, static fn (array $call): bool => $call[0] !== 'find'))
		);
		$this->assertSame([], $this->logged);

		$this->objects->rows = [];
		$this->assertSame([], $store->allForCase('case-1'));
		$this->assertNull($store->latestForCase('case-1'));
	}//end testConfiguredReadsAndWrites()

	public function testUnconfiguredAsksNothing(): void {
		foreach (['register', 'termijn_instance_schema'] as $missing) {
			$this->config = ['register' => 'dossiq', 'termijn_instance_schema' => 'instances', 'other_schema' => 'other'];
			$this->config[$missing] = '';
			$store = $this->store();
			$this->assertNull($store->read('t-1'));
			$this->assertNull($store->latestForCase('case-1'));
			$this->assertSame([], $store->allForCase('case-1'));
		}

		$this->assertNull($this->store()->save('missing_schema', ['x' => 1]));
		$this->assertSame([], $this->store()->allForCase(''));
		$this->noService = true;
		$this->assertNull($this->store()->read('t-1'));
		$this->assertNull($this->store()->latestForCase('case-1'));
		$this->assertSame([], $this->store()->allForCase('case-1'));
		$this->assertNull($this->store()->save('other_schema', []));
		$this->assertSame([], $this->objects->calls);
		$this->assertSame([], $this->logged);
	}//end testUnconfiguredAsksNothing()

	public function testFailures(): void {
		$this->objects->fail = true;
		$store = $this->store();

		$this->assertNull($store->read('t-1'));
		$this->assertNull($store->latestForCase('case-1'));
		$this->assertNull($store->save('other_schema', ['x' => 1]));
		try {
			$store->allForCase('case-1');
			$this->fail('allForCase answered');
		} catch (RefusedException $e) {
			$this->assertSame('term-instances-unreadable', $e->getRule());
			$this->assertSame('The terms on this case could not be read.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertSame('search down', $e->getPrevious()?->getMessage());
		}

		$this->assertSame(
			[
				['warning', 'TermInstanceStore.read failed', ['id' => 't-1', 'error' => 'find down']],
				['error', 'TermInstanceStore persist failed', ['schemaConfigKey' => 'other_schema', 'error' => 'save down']],
				['warning', 'TermInstanceStore.allForCase lookup failed, so the read is refused', ['case' => 'case-1', 'error' => 'search down']],
			],
			$this->logged
		);
	}//end testFailures()

	private function store(): TermInstanceStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->noService ? null : $this->objects);
		$config = $this->config;
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new TermInstanceStore(settingsService: $settings, logger: $logger);
	}//end store()
}//end class
