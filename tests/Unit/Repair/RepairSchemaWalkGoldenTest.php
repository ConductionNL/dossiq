<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillPortalParty;
use OCA\Dossiq\Repair\MapLegacyArchivalActions;
use OCA\Dossiq\Repair\NormaliseCommunicationChannelValues;
use OCA\Dossiq\Repair\RecolourShippedStatusTypes;
use OCA\Dossiq\Service\CommunicationChannel;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTypePalette;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The four repair steps that walk one configured schema page by page: which
 * pages they ask for, what they write, what they log and what they print.
 */
class RepairSchemaWalkGoldenTest extends TestCase {
	/**
	 * The pages asked for, the writes, the log lines and the printed lines of the last run.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * A register double that pages through the rows and refuses one write.
	 *
	 * @param array<int, array<string, mixed>> $rows   The stored rows.
	 * @param string                           $refuse The id whose write fails.
	 *
	 * @return object The double.
	 */
	private function objects(array $rows, string $refuse): object {
		$test = $this;
		return new class($rows, $refuse, $test) {
			/**
			 * @param array<int, array<string, mixed>> $rows   The rows.
			 * @param string                           $refuse The id whose write fails.
			 * @param RepairSchemaWalkGoldenTest       $test   Where the calls are recorded.
			 */
			public function __construct(private array $rows, private string $refuse, private RepairSchemaWalkGoldenTest $test) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One page.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->test->record(kind: 'pages', entry: [$register, $schema, $filters]);
				$size = (int)$filters['_limit'];
				return array_slice($this->rows, (((int)$filters['_page'] - 1) * $size), $size);
			}

			/**
			 * @param string               $objectId The row.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				if ($objectId === $this->refuse) {
					throw new RuntimeException('refused');
				}

				$this->test->record(kind: 'writes', entry: [$objectId, $data, $register, $schema]);
				return $data;
			}

			/**
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $work): void {
				$work();
			}
		};
	}//end objects()

	/**
	 * Record one call.
	 *
	 * @param string $kind  The list.
	 * @param mixed  $entry The call.
	 *
	 * @return void
	 */
	public function record(string $kind, mixed $entry): void {
		$this->seen[$kind][] = $entry;
	}//end record()

	/**
	 * Run a step over a register double.
	 *
	 * @param string       $class     The step.
	 * @param string       $schemaKey The schema the step reads.
	 * @param object|null  $objects   The register double, or null for no OpenRegister.
	 *
	 * @return void
	 */
	private function runStep(string $class, string $schemaKey, ?object $objects): void {
		$this->seen = ['pages' => [], 'writes' => [], 'log' => [], 'out' => []];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', $schemaKey => 'the-schema'][$key] ?? $default)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->seen['log'][] = ['done', $key, $value];
				return true;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->seen['log'][] = [$level, $message];
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->seen['out'][] = $message;
			}
		);

		$step = match ($class) {
			NormaliseCommunicationChannelValues::class => new NormaliseCommunicationChannelValues(settingsService: $settings, appConfig: $appConfig, channels: new CommunicationChannel(), logger: $logger),
			RecolourShippedStatusTypes::class => new RecolourShippedStatusTypes(settingsService: $settings, appConfig: $appConfig, palette: new StatusTypePalette(), logger: $logger),
			default => new $class(settingsService: $settings, appConfig: $appConfig, logger: $logger),
		};
		$this->assertInstanceOf(IRepairStep::class, $step);
		$step->run(output: $output);
	}//end runStep()

	/**
	 * The pages a walk over 201 rows asks for: two, of 200, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function twoPages(): array {
		return [
			['dossiq', 'the-schema', ['_limit' => 200, '_page' => 1]],
			['dossiq', 'the-schema', ['_limit' => 200, '_page' => 2]],
		];
	}//end twoPages()

	/**
	 * Every case with a subject and no party is filled across both pages; a refused write is logged and the run still ends.
	 *
	 * @return void
	 */
	public function testThePortalPartyWalksEveryPage(): void {
		$rows = [];
		for ($i = 1; $i <= 201; $i++) {
			$rows[] = ['id' => 'c-' . $i, 'portalSubject' => 's' . $i];
		}

		$rows[] = ['@self' => ['id' => 'c-self'], 'portalSubject' => 'self'];
		$this->runStep(class: BackfillPortalParty::class, schemaKey: 'case_schema', objects: $this->objects(rows: $rows, refuse: 'c-2'));

		$this->assertSame($this->twoPages(), $this->seen['pages']);
		$this->assertCount(201, $this->seen['writes']);
		$this->assertSame(['c-1', ['portalParty' => 'subject:s1'], 'dossiq', 'the-schema'], $this->seen['writes'][0]);
		$this->assertSame(['c-self', ['portalParty' => 'subject:self'], 'dossiq', 'the-schema'], $this->seen['writes'][200]);
		$this->assertSame(
			[
				['warning', 'Dossiq portal party: case c-2 could not be given its party: refused'],
				['done', 'portal_party_backfilled', '1'],
			],
			$this->seen['log']
		);
		$this->assertSame(['Dossiq portal party: 201 case(s) now name the person they belong to.'], $this->seen['out']);
	}//end testThePortalPartyWalksEveryPage()

	/**
	 * Legacy values are mapped across both pages; a refused write keeps the step for the next run.
	 *
	 * @return void
	 */
	public function testTheArchivalActionsWalkEveryPage(): void {
		$rows = [];
		for ($i = 1; $i <= 200; $i++) {
			$rows[] = ['id' => 'r-' . $i, 'archivalAction' => 'bewaren'];
		}

		$rows[] = ['id' => 'r-a', 'archivalAction' => 'retain'];
		$rows[] = ['@self' => ['id' => 'r-b'], 'archivalAction' => 'destroy'];
		$rows[] = ['id' => '', 'archivalAction' => 'destroy'];
		$this->runStep(class: MapLegacyArchivalActions::class, schemaKey: 'result_type_schema', objects: $this->objects(rows: $rows, refuse: 'r-a'));

		$this->assertSame($this->twoPages(), $this->seen['pages']);
		$this->assertSame([['r-b', ['archivalAction' => 'vernietigen'], 'dossiq', 'the-schema']], $this->seen['writes']);
		$this->assertSame(
			[
				['warning', 'Dossiq archival actions: result type r-a could not be written and keeps "retain" until the next run'],
				['info', 'Dossiq archival actions: result type r-b now reads "vernietigen"; it held "destroy"'],
			],
			$this->seen['log']
		);
		$this->assertSame(['Dossiq archival actions: 1 result type(s) mapped, 1 left for the next run.'], $this->seen['out']);
	}//end testTheArchivalActionsWalkEveryPage()

	/**
	 * Channel values are converted across both pages, and a finished run marks itself done.
	 *
	 * @return void
	 */
	public function testTheChannelsWalkEveryPage(): void {
		$rows = [];
		for ($i = 1; $i <= 200; $i++) {
			$rows[] = ['id' => 'k-' . $i];
		}

		$rows[] = ['@self' => ['id' => 'k-x'], 'communicationChannel' => 'telefoon'];
		$this->runStep(class: NormaliseCommunicationChannelValues::class, schemaKey: 'case_schema', objects: $this->objects(rows: $rows, refuse: ''));

		$this->assertSame($this->twoPages(), $this->seen['pages']);
		$this->assertCount(1, $this->seen['writes']);
		$this->assertSame('k-x', $this->seen['writes'][0][0]);
		$this->assertSame(['done', 'communication_channel_values_normalised', '1'], end($this->seen['log']));
		$this->assertCount(1, $this->seen['out']);
		$this->assertStringStartsWith('Dossiq communication channels: 1 case(s) converted to a channel slug, 0 left', $this->seen['out'][0]);
	}//end testTheChannelsWalkEveryPage()

	/**
	 * The status walk asks for both pages and leaves rows it does not own alone.
	 *
	 * @return void
	 */
	public function testTheStatusColoursWalkEveryPage(): void {
		$rows = [];
		for ($i = 1; $i <= 201; $i++) {
			$rows[] = ['id' => 's-' . $i, '@self' => ['owner' => 'someone']];
		}

		$this->runStep(class: RecolourShippedStatusTypes::class, schemaKey: 'status_type_schema', objects: $this->objects(rows: $rows, refuse: ''));

		$this->assertSame($this->twoPages(), $this->seen['pages']);
		$this->assertSame([], $this->seen['writes']);
		$this->assertSame(['Dossiq status colours: 0 shipped status(es) took their board colour, 0 left for the next run.'], $this->seen['out']);
	}//end testTheStatusColoursWalkEveryPage()

	/**
	 * Without OpenRegister, or without the schema, each step prints its own line and reads nothing.
	 *
	 * @return void
	 */
	public function testEachStepSaysWhenItsSchemaIsUnavailable(): void {
		$expected = [
			BackfillPortalParty::class => ['case_schema', 'Dossiq portal party: OpenRegister or the case schema is unavailable; nothing filled.'],
			MapLegacyArchivalActions::class => ['result_type_schema', 'Dossiq archival actions: OpenRegister or the result type schema is unavailable; nothing mapped.'],
			NormaliseCommunicationChannelValues::class => ['case_schema', 'Dossiq communication channels: OpenRegister or the case schema is unavailable; nothing converted.'],
			RecolourShippedStatusTypes::class => ['status_type_schema', 'Dossiq status colours: OpenRegister or the status type schema is unavailable; nothing recoloured.'],
		];
		foreach ($expected as $class => [$schemaKey, $line]) {
			$this->runStep(class: $class, schemaKey: $schemaKey, objects: null);
			$this->assertSame([$line], $this->seen['out'], $class);

			$this->runStep(class: $class, schemaKey: 'another_schema', objects: $this->objects(rows: [], refuse: ''));
			$this->assertSame([$line], $this->seen['out'], $class);
			$this->assertSame([], $this->seen['pages'], $class);
		}
	}//end testEachStepSaysWhenItsSchemaIsUnavailable()
}//end class
