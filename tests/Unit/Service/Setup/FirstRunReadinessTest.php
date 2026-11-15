<?php

/**
 * Unit tests for the first-run readiness reader.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Setup;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Setup\FirstRunReadiness;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each readiness item reads its own fact, and a failing read is reported, not thrown.
 *
 * @covers \OCA\Dossiq\Service\Setup\FirstRunReadiness
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class FirstRunReadinessTest extends TestCase {

	/**
	 * An object service answering rows per schema slug, recording the searches.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Schema slug to rows.
	 *
	 * @return object The stand-in, with a public `$calls` log.
	 */
	private function objectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * The searches asked for.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema The rows.
			 */
			public function __construct(private readonly array $rowsBySchema) {
			}

			/**
			 * Search by slug.
			 *
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @param array  $filters  The filters.
			 * @param bool   ...$scope The scope flags.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool ...$scope): array {
				$this->calls[] = [$register, $schema, $filters];
				return ($this->rowsBySchema[$schema] ?? []);
			}
		};
	}//end objectService()

	/**
	 * Build the reader.
	 *
	 * @param array<string, object|null> $classes       OpenRegister class name to instance.
	 * @param object|null                $objectService The object service.
	 * @param array<string, string>      $config        Dossiq setting to value.
	 * @param string                     $mailAccount   The configured mail account id.
	 *
	 * @return FirstRunReadiness The reader.
	 */
	private function readiness(array $classes = [], ?object $objectService = null, array $config = [], string $mailAccount = ''): FirstRunReadiness {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => ($classes[$class] ?? null)
		);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (($app === 'dossiq' && $key === 'email_mail_account_id') ? $mailAccount : $default)
		);

		return new FirstRunReadiness(settingsService: $settings, appConfig: $appConfig);
	}//end readiness()

	/**
	 * An organisation mapper answering the given rows.
	 *
	 * @param array<int, mixed> $rows What findAll answers.
	 *
	 * @return object The mapper, with a public `$limits` log.
	 */
	private function organisations(array $rows): object {
		return new class($rows) {
			/**
			 * The limits asked for.
			 *
			 * @var array<int, int>
			 */
			public array $limits = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Find organisations.
			 *
			 * @param int $limit The limit.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(int $limit = 0): array {
				$this->limits[] = $limit;
				return $this->rows;
			}
		};
	}//end organisations()

	/**
	 * The report keyed by item id.
	 *
	 * @param FirstRunReadiness $readiness The reader.
	 *
	 * @return array<string, array{done: bool, failure: string}> Id to its state.
	 */
	private function states(FirstRunReadiness $readiness): array {
		$states = [];
		foreach ($readiness->report() as $row) {
			$states[$row['id']] = ['done' => $row['done'], 'failure' => $row['failure']];
		}

		return $states;
	}//end states()

	/**
	 * The shipped items are declared, and the report carries their copy in order.
	 *
	 * @return void
	 */
	public function testTheReportFollowsTheDeclaredItems(): void {
		$readiness = $this->readiness();
		$declared = $readiness->declared();

		self::assertSame(
			['organisation', 'mail-account', 'published-case-type', 'role-with-holder', 'working-calendar'],
			array_column($declared, 'id')
		);

		$report = $readiness->report();
		self::assertSame(array_column($declared, 'id'), array_column($report, 'id'));
		foreach ($report as $index => $row) {
			self::assertSame(['id', 'title', 'body', 'screen', 'done', 'failure'], array_keys($row));
			self::assertSame((string)($declared[$index]['title'] ?? ''), $row['title']);
			self::assertSame((string)($declared[$index]['screen'] ?? ''), $row['screen']);
		}
	}//end testTheReportFollowsTheDeclaredItems()

	/**
	 * With nothing configured, every item is open and each failure says why.
	 *
	 * @return void
	 */
	public function testAnEmptyInstanceReportsEveryFailure(): void {
		self::assertSame(
			[
				'organisation' => ['done' => false, 'failure' => 'OpenRegister does not answer for organisations'],
				'mail-account' => ['done' => false, 'failure' => ''],
				'published-case-type' => ['done' => false, 'failure' => 'OpenRegister is not available'],
				'role-with-holder' => ['done' => false, 'failure' => 'OpenRegister is not available'],
				'working-calendar' => ['done' => false, 'failure' => ''],
			],
			$this->states(readiness: $this->readiness())
		);
	}//end testAnEmptyInstanceReportsEveryFailure()

	/**
	 * A configured instance has every item done, and the searches are the right ones.
	 *
	 * @return void
	 */
	public function testAReadyInstanceHasEveryItemDone(): void {
		$organisations = $this->organisations(rows: [new \stdClass()]);
		$objects = $this->objectService(
			rowsBySchema: [
				'caseType' => [['id' => 'ct-1']],
				'role' => [['participant' => '  '], ['participant' => 'ilse']],
			]
		);

		$states = $this->states(
			readiness: $this->readiness(
				classes: [
					FirstRunReadiness::ORGANISATION_MAPPER_CLASS => $organisations,
					FirstRunReadiness::CALENDAR_SERVICE_CLASS => new \stdClass(),
				],
				objectService: $objects,
				config: ['register' => 'dossiq', 'case_type_schema' => 'caseType', 'role_schema' => 'role'],
				mailAccount: '3',
			)
		);

		self::assertSame(array_fill_keys(array_keys($states), ['done' => true, 'failure' => '']), $states);
		self::assertSame([1], $organisations->limits);
		self::assertSame(
			[
				['dossiq', 'caseType', ['isDraft' => false]],
				['dossiq', 'role', []],
			],
			$objects->calls
		);
	}//end testAReadyInstanceHasEveryItemDone()

	/**
	 * No organisations, no published case type and no role with a holder are open, not failed.
	 *
	 * @return void
	 */
	public function testAbsentFactsAreOpenWithoutAFailure(): void {
		$states = $this->states(
			readiness: $this->readiness(
				classes: [FirstRunReadiness::ORGANISATION_MAPPER_CLASS => $this->organisations(rows: [])],
				objectService: $this->objectService(rowsBySchema: ['role' => [['participant' => ''], ['name' => 'no holder']]]),
				config: ['register' => 'dossiq', 'case_type_schema' => 'caseType', 'role_schema' => 'role'],
			)
		);

		self::assertSame(['done' => false, 'failure' => ''], $states['organisation']);
		self::assertSame(['done' => false, 'failure' => ''], $states['published-case-type']);
		self::assertSame(['done' => false, 'failure' => ''], $states['role-with-holder']);
	}//end testAbsentFactsAreOpenWithoutAFailure()

	/**
	 * An unconfigured schema names the setting that is missing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaNamesTheSetting(): void {
		$objects = $this->objectService(rowsBySchema: []);
		$states = $this->states(
			readiness: $this->readiness(objectService: $objects, config: ['register' => 'dossiq', 'role_schema' => 'role'])
		);

		self::assertSame(['done' => false, 'failure' => 'the "case_type_schema" schema is not configured'], $states['published-case-type']);
		self::assertSame(['done' => false, 'failure' => ''], $states['role-with-holder']);
		self::assertSame([['dossiq', 'role', []]], $objects->calls);

		$noRegister = $this->states(readiness: $this->readiness(objectService: $objects, config: ['role_schema' => 'role']));
		self::assertSame('the "role_schema" schema is not configured', $noRegister['role-with-holder']['failure']);
	}//end testAnUnconfiguredSchemaNamesTheSetting()

	/**
	 * A reader that throws is reported as open with its message.
	 *
	 * @return void
	 */
	public function testAThrowingReaderIsReportedNotThrown(): void {
		$mapper = new class {
			/**
			 * Fails.
			 *
			 * @param int $limit The limit.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function findAll(int $limit = 0): array {
				throw new RuntimeException('database gone');
			}
		};

		$states = $this->states(readiness: $this->readiness(classes: [FirstRunReadiness::ORGANISATION_MAPPER_CLASS => $mapper]));

		self::assertSame(['done' => false, 'failure' => 'database gone'], $states['organisation']);
	}//end testAThrowingReaderIsReportedNotThrown()

	/**
	 * Tour steps on a declared page or menu entry are fine; others are missing or unverifiable.
	 *
	 * @return void
	 */
	public function testBrokenTourStepsNameTheirState(): void {
		$manifest = [
			'pages' => [['id' => 'cases'], ['nope' => 'no id']],
			'menu' => [['id' => 'settings', 'children' => [['id' => 'settings-mail']]]],
			'walkthrough' => [
				'tours' => [
					[
						'id' => 'intro',
						'steps' => [
							['id' => 's1', 'target' => ['kind' => 'page', 'ref' => 'cases']],
							['id' => 's2', 'target' => ['kind' => 'nav-item', 'ref' => 'settings-mail']],
							['id' => 's3', 'target' => ['kind' => 'page', 'ref' => 'gone']],
							['id' => 's4', 'target' => ['kind' => 'element', 'selector' => '#save']],
							['id' => 's5'],
						],
					],
					['id' => 'second', 'steps' => [['id' => 't1', 'target' => ['kind' => 'nav-item', 'ref' => 'settings']]]],
				],
			],
		];

		self::assertSame(
			[
				['tour' => 'intro', 'step' => 's3', 'surface' => 'gone', 'kind' => 'page', 'state' => 'missing'],
				['tour' => 'intro', 'step' => 's4', 'surface' => '#save', 'kind' => 'element', 'state' => 'unverifiable'],
				['tour' => 'intro', 'step' => 's5', 'surface' => '', 'kind' => '', 'state' => 'unverifiable'],
			],
			$this->readiness()->brokenTourSteps(manifest: $manifest)
		);
	}//end testBrokenTourStepsNameTheirState()

	/**
	 * A manifest without tours has no broken steps.
	 *
	 * @return void
	 */
	public function testAManifestWithoutToursHasNoBrokenSteps(): void {
		self::assertSame([], $this->readiness()->brokenTourSteps(manifest: ['pages' => [['id' => 'cases']]]));
		self::assertSame([], $this->readiness()->brokenTourSteps(manifest: []));
	}//end testAManifestWithoutToursHasNoBrokenSteps()
}//end class
