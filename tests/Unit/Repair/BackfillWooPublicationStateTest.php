<?php

/**
 * Existing Woo cases get the publication state their decision already holds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillWooPublicationState;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Design D-2 through the repair step's run().
 *
 * @covers \OCA\Dossiq\Repair\BackfillWooPublicationState
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class BackfillWooPublicationStateTest extends TestCase {

	/**
	 * Cases and decisions.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * App config values the step wrote.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Output lines.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * Reset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->config = [];
		$this->said = [];
	}//end setUp()

	/**
	 * Run the step once.
	 *
	 * @param bool $withOpenRegister Whether OpenRegister answers.
	 *
	 * @return void
	 */
	private function runStep(bool $withOpenRegister = true): void {
		$keys = ['register' => 'dossiq', 'decision_schema' => 'decision', 'case_schema' => 'case'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withOpenRegister === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($keys[$key] ?? $default)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.example' . $path);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		(new BackfillWooPublicationState(
			settingsService: $settings,
			appConfig: $appConfig,
			logger: new NullLogger(),
			urlGenerator: $urls,
		))->run($output);
	}//end runStep()

	/**
	 * Each Woo decision's state lands on its case, valid against the real case schema.
	 *
	 * @return void
	 */
	public function testEachWooCaseGetsTheStateItsDecisionHolds(): void {
		$this->store->seed(schema: 'case', uuid: 'c-pub', row: ['title' => 'Woo 1']);
		$this->store->seed(schema: 'case', uuid: 'c-wd', row: ['title' => 'Woo 2']);
		$this->store->seed(schema: 'case', uuid: 'c-ready', row: ['title' => 'Woo 3']);
		$this->store->seed(schema: 'case', uuid: 'c-other', row: ['title' => 'Vergunning']);
		$this->store->seed(schema: 'decision', uuid: 'd-pub', row: [
			'case' => 'c-pub',
			'wooSummary' => ['openbaar' => 2],
			'wooPublication' => ['publicationId' => 'p-1', 'publicationUrl' => '/apps/opencatalogi/publications/p-1', 'status' => 'published'],
		]);
		$this->store->seed(schema: 'decision', uuid: 'd-wd', row: [
			'case' => 'c-wd',
			'wooSummary' => ['openbaar' => 1],
			'wooPublication' => ['publicationId' => 'p-2', 'publicationUrl' => '/apps/opencatalogi/publications/p-2', 'status' => 'withdrawn'],
		]);
		$this->store->seed(schema: 'decision', uuid: 'd-ready', row: ['case' => 'c-ready', 'wooSummary' => ['openbaar' => 0]]);
		$this->store->seed(schema: 'decision', uuid: 'd-other', row: ['case' => 'c-other', 'title' => 'Vergunning verleend']);

		$this->runStep();

		$published = $this->store->row(schema: 'case', uuid: 'c-pub');
		$this->assertSame('published', $published['wooPublicationStatus']);
		$this->assertSame('https://gemeente.example/apps/opencatalogi/publications/p-1', $published['wooPublicationUrl']);
		$this->assertSame('withdrawn', $this->store->row(schema: 'case', uuid: 'c-wd')['wooPublicationStatus']);
		$this->assertSame('ready', $this->store->row(schema: 'case', uuid: 'c-ready')['wooPublicationStatus']);
		$this->assertArrayNotHasKey('wooPublicationStatus', $this->store->row(schema: 'case', uuid: 'c-other'));

		$schema = new RealSchemaValidator();
		foreach (['c-pub', 'c-wd', 'c-ready'] as $uuid) {
			$row = $this->store->row(schema: 'case', uuid: $uuid);
			$changes = array_intersect_key($row, ['wooPublicationStatus' => true, 'wooPublicationUrl' => true]);
			$this->assertSame([], $schema->errors(slug: 'case', payload: $changes, creating: false), $uuid);
		}
	}//end testEachWooCaseGetsTheStateItsDecisionHolds()

	/**
	 * A second run writes nothing: the persisted key stops it, and so would the equal state.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->store->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Woo']);
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'c-1', 'wooSummary' => ['openbaar' => 1]]);

		$this->runStep();
		$writes = $this->store->writes;
		$this->runStep();

		$this->assertSame(1, $writes);
		$this->assertSame($writes, $this->store->writes);
		$this->assertSame(BackfillWooPublicationState::VERSION, $this->config[BackfillWooPublicationState::CONFIG_KEY]);
	}//end testASecondRunChangesNothing()

	/**
	 * A case that already shows its state is not written again.
	 *
	 * @return void
	 */
	public function testACaseThatAlreadyShowsItsStateIsLeftAlone(): void {
		$this->store->seed(schema: 'case', uuid: 'c-1', row: ['wooPublicationStatus' => 'ready']);
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'c-1', 'wooSummary' => ['openbaar' => 1]]);

		$this->runStep();

		$this->assertSame(0, $this->store->writes);
	}//end testACaseThatAlreadyShowsItsStateIsLeftAlone()

	/**
	 * Without OpenRegister the step says so, writes nothing and does not mark itself done.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkipsAndStaysUnmarked(): void {
		$this->runStep(withOpenRegister: false);

		$this->assertArrayNotHasKey(BackfillWooPublicationState::CONFIG_KEY, $this->config);
		$this->assertNotEmpty(array_filter($this->said, static fn (string $line): bool => str_contains($line, 'skipping')));
	}//end testWithoutOpenRegisterItSkipsAndStaysUnmarked()
}//end class
