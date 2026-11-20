<?php

/**
 * The Woo publication state backfill on the paths that go wrong: an
 * unreadable decision page, a case that is gone, a write that fails, and an
 * instance without a URL generator.
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
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Failure tallies and the completion key.
 *
 * @covers \OCA\Dossiq\Repair\BackfillWooPublicationState
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Publication\PublicationResultLink
 * @uses \OCA\Dossiq\Repair\WooBackfillStore
 */
class BackfillWooPublicationStateEdgesTest extends TestCase {

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
	 * Build the step, without a URL generator.
	 *
	 * @param LoggerInterface|null $logger The logger, a stub when null.
	 *
	 * @return BackfillWooPublicationState The step.
	 */
	private function step(?LoggerInterface $logger = null): BackfillWooPublicationState {
		$keys = ['register' => 'dossiq', 'decision_schema' => 'decision', 'case_schema' => 'case'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($keys[$key] ?? $default)
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new BackfillWooPublicationState(
			settingsService: $settings,
			appConfig: $appConfig,
			logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end step()

	/**
	 * Run a step.
	 *
	 * @param BackfillWooPublicationState $step The step.
	 *
	 * @return void
	 */
	private function runStep(BackfillWooPublicationState $step): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		$step->run($output);
	}//end runStep()

	/**
	 * An unreadable decision page is logged, reported as nothing done, and
	 * leaves the backfill unmarked so the next upgrade tries again.
	 *
	 * @return void
	 */
	public function testAnUnreadableDecisionPageLeavesTheBackfillUnmarked(): void {
		$this->store->missingSchemas = ['decision'];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the Woo publication state backfill could not read the decisions',
			$this->callback(callback: static fn (array $context): bool => $context['app'] === 'dossiq' && str_contains($context['error'], 'decision'))
		);

		$this->runStep(step: $this->step(logger: $logger));

		$this->assertSame(
			expected: ['Woo publication state backfill: 0 cases updated, 0 already right, 0 without their case, 0 failed.'],
			actual: $this->said
		);
		$this->assertArrayNotHasKey(key: BackfillWooPublicationState::CONFIG_KEY, array: $this->config);
	}//end testAnUnreadableDecisionPageLeavesTheBackfillUnmarked()

	/**
	 * A decision whose case is gone counts as missing, writes nothing, and
	 * does not stop the backfill from being marked done.
	 *
	 * @return void
	 */
	public function testADecisionWithoutItsCaseCountsAsMissing(): void {
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'gone', 'wooSummary' => ['openbaar' => 1]]);

		$this->runStep(step: $this->step());

		$this->assertSame(expected: 0, actual: $this->store->writes);
		$this->assertSame(
			expected: ['Woo publication state backfill: 0 cases updated, 0 already right, 1 without their case, 0 failed.'],
			actual: $this->said
		);
		$this->assertSame(expected: BackfillWooPublicationState::VERSION, actual: $this->config[BackfillWooPublicationState::CONFIG_KEY]);
	}//end testADecisionWithoutItsCaseCountsAsMissing()

	/**
	 * A failing case write is logged with the case id, counted as failed, and
	 * leaves the backfill unmarked.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsCountedAndLeavesTheBackfillUnmarked(): void {
		$this->store->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Woo']);
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['case' => 'c-1', 'wooSummary' => ['openbaar' => 1]]);
		$this->store->refusedSchemas = ['case'];

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the Woo publication state backfill could not write a case',
			['app' => 'dossiq', 'caseId' => 'c-1', 'error' => 'Save refused for case']
		);

		$this->runStep(step: $this->step(logger: $logger));

		$this->assertSame(
			expected: ['Woo publication state backfill: 0 cases updated, 0 already right, 0 without their case, 1 failed.'],
			actual: $this->said
		);
		$this->assertArrayNotHasKey(key: BackfillWooPublicationState::CONFIG_KEY, array: $this->config);
	}//end testAFailingWriteIsCountedAndLeavesTheBackfillUnmarked()

	/**
	 * Without a URL generator the stored path is shown as it is, and an
	 * already absolute link is never rewritten either way.
	 *
	 * @return void
	 */
	public function testWithoutAUrlGeneratorThePathIsKeptAsStored(): void {
		$step = $this->step();

		$this->assertSame(
			expected: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => '/apps/opencatalogi/publications/p-1'],
			actual: $step->stateFor(
				['wooSummary' => ['openbaar' => 1], 'wooPublication' => ['status' => 'published', 'publicationUrl' => '/apps/opencatalogi/publications/p-1']]
			)
		);
		$this->assertSame(
			expected: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 'https://elders.example/p-1'],
			actual: $step->stateFor(
				['wooSummary' => ['openbaar' => 1], 'wooPublication' => ['status' => 'published', 'publicationUrl' => 'https://elders.example/p-1']]
			)
		);
	}//end testWithoutAUrlGeneratorThePathIsKeptAsStored()

	/**
	 * A malformed publication block reads as ready, a published state without
	 * a link reads as ready, and a decision without a Woo summary implies nothing.
	 *
	 * @return void
	 */
	public function testMalformedOrIncompletePublicationsReadAsReady(): void {
		$step = $this->step();

		$this->assertSame(
			expected: ['wooPublicationStatus' => 'ready'],
			actual: $step->stateFor(['wooSummary' => ['openbaar' => 1], 'wooPublication' => 'published'])
		);
		$this->assertSame(
			expected: ['wooPublicationStatus' => 'ready'],
			actual: $step->stateFor(['wooSummary' => ['openbaar' => 1], 'wooPublication' => ['status' => 'published']])
		);
		$this->assertSame(expected: [], actual: $step->stateFor(['wooSummary' => [], 'wooPublication' => ['status' => 'published']]));
	}//end testMalformedOrIncompletePublicationsReadAsReady()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(expected: 'Show the Woo publication state on existing Woo cases', actual: $this->step()->getName());
	}//end testTheStepNamesItself()
}//end class
