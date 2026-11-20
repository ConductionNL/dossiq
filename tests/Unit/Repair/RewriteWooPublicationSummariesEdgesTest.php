<?php

/**
 * The Woo summary rewrite on the paths the main test leaves alone: no
 * OpenRegister, an unconfigured target, a target that cannot be read, and a
 * case title that cannot be read.
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RewriteWooPublicationSummaries;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\RewriteWooPublicationSummaries
 *
 * @uses \OCA\Dossiq\Repair\SummaryTarget
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Woo\WooPublicationSummary
 */
class RewriteWooPublicationSummariesEdgesTest extends TestCase {
	private const OLD = 'WOO besluit voor zaak b9e5d911-16ef-4cbc-9d6d-659b5585e1f7';

	/**
	 * Output lines.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * A store whose numeric-register search throws, whose slug search answers
	 * one publication, and whose case read fails with something other than
	 * "not found".
	 *
	 * @return object The fake.
	 */
	private function store(): object {
		return new class(self::OLD) {
			/**
			 * @var array<int, array{id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @param string $old The old summary text.
			 */
			public function __construct(private readonly string $old) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 *
			 * @throws RuntimeException Always: the decision schema is unreadable.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('decision table is locked');
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [['id' => 'pub-1', 'summary' => $this->old, 'description' => 'Eigen tekst']];
			}

			/**
			 * @param string                 $id The id.
			 * @param ObjectFindOptions|null $options The options.
			 * @param bool                   $_rbac Ignored.
			 * @param bool                   $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException Always: the case schema is unreadable.
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('case table is locked');
			}

			/**
			 * @param ObjectPatch $patch The patch.
			 * @param bool        $_rbac Ignored.
			 * @param bool        $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->patches[] = ['id' => $patch->objectId, 'data' => $patch->data];
				return $patch->data;
			}
		};
	}//end store()

	/**
	 * The step over a store and a configuration.
	 *
	 * @param object|null          $store       The object service, or null for none.
	 * @param array<string, string> $config      Dossiq config values.
	 * @param string               $publication The Woo publication register and schema.
	 * @param LoggerInterface|null $logger      The logger.
	 *
	 * @return RewriteWooPublicationSummaries
	 */
	private function step(?object $store, array $config, string $publication, ?LoggerInterface $logger = null): RewriteWooPublicationSummaries {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getWooPublicationConfigValue')->willReturn($publication);

		return new RewriteWooPublicationSummaries(
			settingsService: $settings,
			logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end step()

	/**
	 * An output that records what it was told.
	 *
	 * @return IOutput The output.
	 */
	private function repairOutput(): IOutput {
		$this->said = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		return $output;
	}//end output()

	/**
	 * Without OpenRegister the rewrite says so and does nothing else.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$this->step(store: null, config: [], publication: 'publication')->run($this->repairOutput());

		$this->assertSame(expected: ['OpenRegister unavailable, skipping the Woo summary rewrite.'], actual: $this->said);
	}//end testWithoutOpenRegisterItSkips()

	/**
	 * An unconfigured target is reported as skipped by name, and nothing is read for it.
	 *
	 * @return void
	 */
	public function testUnconfiguredTargetsAreSkippedByName(): void {
		$store = $this->store();

		$this->step(store: $store, config: [], publication: '')->run($this->repairOutput());

		$this->assertSame(
			expected: [
				'Woo summary rewrite: publications not configured, skipped.',
				'Woo summary rewrite: decisions not configured, skipped.',
			],
			actual: $this->said
		);
		$this->assertSame(expected: [], actual: $store->patches);
	}//end testUnconfiguredTargetsAreSkippedByName()

	/**
	 * A target that cannot be read is logged and reported as skipped, while
	 * the other target is still rewritten; a case title that cannot be read
	 * is logged and the summary falls back to the words without a title.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetIsSkippedAndAnUnreadableTitleFallsBack(): void {
		$store = $this->store();
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq: the Woo summary rewrite could not read a case title',
			['app' => 'dossiq', 'case' => 'b9e5d911-16ef-4cbc-9d6d-659b5585e1f7', 'error' => 'case table is locked']
		);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the Woo summary rewrite could not read the decisions',
			['app' => 'dossiq', 'error' => 'decision table is locked']
		);

		$this->step(
			store: $store,
			config: ['register' => '23', 'case_schema' => '5', 'decision_schema' => '7'],
			publication: 'publication',
			logger: $logger
		)
			->run($this->repairOutput());

		$this->assertSame(expected: [['id' => 'pub-1', 'data' => ['summary' => 'Besluit op een Woo-verzoek']]], actual: $store->patches);
		$this->assertSame(
			expected: [
				'Woo summary rewrite, publications: 1 rewritten, 0 failed.',
				'Woo summary rewrite: decisions could not be read, skipped.',
			],
			actual: $this->said
		);
	}//end testAnUnreadableTargetIsSkippedAndAnUnreadableTitleFallsBack()

	/**
	 * The step names what it rewrites.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(
			expected: 'Rewrite the old "WOO besluit voor zaak <uuid>" summary on Woo decisions and publications',
			actual: $this->step(store: null, config: [], publication: '')->getName()
		);
	}//end testTheStepNamesItself()
}//end class
