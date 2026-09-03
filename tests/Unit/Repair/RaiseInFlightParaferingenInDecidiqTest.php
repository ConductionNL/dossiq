<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RaiseInFlightParaferingenInDecidiq;
use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the in-flight parafering migration.
 *
 * The identity test is the one that matters, the same reason its sibling
 * MigrateParafeerroutesToDecidiq documents: a repair step has no session, and
 * without a system identity every write is refused as Anonymous while the
 * upgrade still reports success. A step that established no identity and
 * carried on would strand exactly the voorstellen it exists to rescue.
 */
class RaiseInFlightParaferingenInDecidiqTest extends TestCase {

	/**
	 * Voorstel rows the fake register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The subjects handed to the decision app.
	 *
	 * @var array<int, string>
	 */
	private array $raised = [];

	/**
	 * Lines reported to the migration output.
	 *
	 * @var array<int, string>
	 */
	private array $reported = [];

	/**
	 * A fake object service with (or without) a system identity.
	 *
	 * @param bool $withRunAsSystem Whether it exposes runAsSystem().
	 *
	 * @return object The fake.
	 */
	private function objectService(bool $withRunAsSystem = true): object {
		$rows = &$this->rows;

		if ($withRunAsSystem === false) {
			return new class ($rows) {
				/**
				 * @param array<int, array<string, mixed>> $rows The rows.
				 */
				public function __construct(private array &$rows) {
				}

				/**
				 * @param string $register The register slug.
				 * @param string $schema The schema slug.
				 * @param array<string, mixed> $filters The filters.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
					return $this->rows;
				}
			};
		}

		return new class ($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private array &$rows) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed The result.
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->rows;
			}
		};
	}

	/**
	 * The migration output collector.
	 *
	 * @return IOutput The output.
	 */
	private function migrationOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$capture = function (string $line): void {
			$this->reported[] = $line;
		};
		$output->method('info')->willReturnCallback($capture);
		$output->method('warning')->willReturnCallback($capture);

		return $output;
	}

	/**
	 * Build the repair step.
	 *
	 * @param object|null $objectService The object service, or null.
	 * @param bool $available Whether the decision app is available.
	 * @param array<string, mixed>|null $route The route the directory resolves.
	 *
	 * @return RaiseInFlightParaferingenInDecidiq The step.
	 */
	private function step(?object $objectService, bool $available = true, ?array $route = ['id' => 'r-1', 'steps' => [['order' => 1]]], bool $holds = true): RaiseInFlightParaferingenInDecidiq {
		$delegation = $this->createMock(ParaferingDelegationService::class);
		$delegation->method('isAvailable')->willReturn($available);
		if ($holds === false) {
			$delegation->method('holdRoute')->willThrowException(new RuntimeException('the decision app refused'));
		} else {
			$delegation->method('holdRoute')->willReturnCallback(
				function (array $r, string $actorId = '', string $subject = '', string $subjectSchema = ''): string {
					$this->raised[] = $subject;

					return 'ar-1';
				}
			);
		}

		$routes = $this->createMock(ParafeerrouteDirectory::class);
		// The voorstel schema declares no caseType: the type is derived from
		// the linked case, and the route lookup is keyed to the derived id so
		// a regression back to reading `caseType` off the voorstel misses.
		$routes->method('caseTypeOfVoorstel')->willReturnCallback(
			function (array $voorstel): string {
				$this->assertArrayNotHasKey(
					'caseType',
					$voorstel,
					'The stored voorstel carries no caseType; the schema does not declare one.'
				);

				return ((string)($voorstel['case'] ?? '') !== '') ? 'ct-1' : '';
			}
		);
		$routes->method('localRoute')->willReturnCallback(
			static fn (string $caseTypeId): ?array => ($caseTypeId === 'ct-1') ? $route : null
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'voorstel_schema' => 'proposal',
				default => $default,
			}
		);

		return new RaiseInFlightParaferingenInDecidiq(
			$delegation,
			$routes,
			$settings,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * Only voorstellen still in parafering are re-raised.
	 *
	 * @return void
	 */
	public function testItReRaisesOnlyInFlightVoorstellen(): void {
		$this->rows = [
			['id' => 'v-1', 'status' => 'in_parafering', 'case' => 'c-1'],
			['id' => 'v-2', 'status' => 'ter_accordering', 'case' => 'c-1'],
			['id' => 'v-3', 'status' => 'geaccordeerd', 'case' => 'c-1'],
			['id' => 'v-4', 'status' => 'draft', 'case' => 'c-1'],
		];

		$this->step(objectService: $this->objectService())->run($this->migrationOutput());

		$this->assertSame(['v-1', 'v-2'], $this->raised);
	}

	/**
	 * With no decision app it warns and re-raises nothing.
	 *
	 * @return void
	 */
	public function testWithNoDecisionAppItReRaisesNothing(): void {
		$this->rows = [['id' => 'v-1', 'status' => 'in_parafering', 'case' => 'c-1']];

		$this->step(objectService: $this->objectService(), available: false)->run($this->migrationOutput());

		$this->assertSame([], $this->raised);
		$this->assertNotSame([], $this->reported, 'The skip is reported, not silent.');
	}

	/**
	 * A voorstel whose case type has no route is skipped, not raised.
	 *
	 * @return void
	 */
	public function testAnUnroutableVoorstelIsSkipped(): void {
		$this->rows = [['id' => 'v-1', 'status' => 'in_parafering', 'case' => 'c-1']];

		$this->step(objectService: $this->objectService(), route: null)->run($this->migrationOutput());

		$this->assertSame([], $this->raised);
	}

	/**
	 * A voorstel the decision app refuses is counted failed and named, not
	 * silently dropped.
	 *
	 * @return void
	 */
	public function testARefusedReRaiseIsCountedAndReported(): void {
		$this->rows = [['id' => 'v-1', 'status' => 'in_parafering', 'case' => 'c-1']];

		$this->step(objectService: $this->objectService(), holds: false)->run($this->migrationOutput());

		$this->assertSame([], $this->raised);
		$this->assertNotSame(
			[],
			array_filter($this->reported, static fn (string $line): bool => str_contains($line, 'v-1')),
			'The failed voorstel is named in the migration output.'
		);
	}

	/**
	 * 🔴 No system identity FAILS rather than running as Anonymous.
	 *
	 * @return void
	 */
	public function testItFailsWithoutASystemIdentity(): void {
		$this->rows = [['id' => 'v-1', 'status' => 'in_parafering', 'case' => 'c-1']];

		$this->expectException(exception: RuntimeException::class);
		$this->step(objectService: $this->objectService(withRunAsSystem: false))->run($this->migrationOutput());
	}
}
