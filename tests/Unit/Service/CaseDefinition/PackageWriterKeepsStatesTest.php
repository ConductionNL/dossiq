<?php
/**
 * A package install writes its example cases in the state they ship in.
 *
 * OpenRegister refuses a create in any state but the schema's initial one,
 * except inside its scope for an administrator's import of rows in their own
 * states. A package's example case is in flight (a Jeugdwet melding in
 * "Onderzoek"), so the install runs inside that scope; without it the whole
 * package failed (live 26 Nov 2026).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
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
 * @spec openspec/changes/zuiddrecht-example-set/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Kernel\Contracts\Core\LifecycleStatesKeeperInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for PackageWriter's write inside OpenRegister's keep-states scope.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageWriter
 * @uses \OCA\Dossiq\Service\CaseDefinition\AdoptedCaseTypeFill
 * @uses \OCA\Dossiq\Service\CaseDefinition\ExistingRowMatcher
 * @uses \OCA\Dossiq\Service\CaseDefinition\RowTarget
 * @uses \OCA\Dossiq\Service\CaseDefinition\RowMatchRun
 */
class PackageWriterKeepsStatesTest extends TestCase {

	/**
	 * A register that refuses to create a case outside the keep-states scope, as OpenRegister does for a case in flight.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The settings that point the writer at the register.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * The register, refusing cases until a scope lets them in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new InMemoryRegister();
		$this->register->refusedSchemas = ['case'];

		$settings = $this->getMockBuilder(className: SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);
		$this->settings = $settings;
	}//end setUp()

	/**
	 * A keeper that lets cases in for the length of the operation, and counts its calls.
	 *
	 * @param InMemoryRegister $register The register it opens.
	 *
	 * @return LifecycleStatesKeeperInterface The keeper, with a public `calls` count.
	 */
	private function keeper(InMemoryRegister $register): LifecycleStatesKeeperInterface {
		return new class ($register) implements LifecycleStatesKeeperInterface {
			/**
			 * How often the scope was entered.
			 *
			 * @var integer
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param InMemoryRegister $register The register it opens.
			 */
			public function __construct(private readonly InMemoryRegister $register) {
			}

			/**
			 * Run the operation with cases accepted, and refuse them again after.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed What the operation returned.
			 */
			public function keepingStates(callable $operation): mixed {
				$this->calls++;
				$refused = $this->register->refusedSchemas;
				$this->register->refusedSchemas = [];
				try {
					return $operation();
				} finally {
					$this->register->refusedSchemas = $refused;
				}
			}
		};
	}//end keeper()

	/**
	 * The package's rows, a case shipped in a later state.
	 *
	 * @return array<int, array<string, mixed>> The groups.
	 */
	private function packageRows(): array {
		return [['schemaKey' => 'case_schema', 'rows' => [['id' => 'c-1', 'title' => 'Melding', 'status' => 'onderzoek']]]];
	}//end groups()

	/**
	 * A case in flight is written inside the scope, once per install.
	 *
	 * @return void
	 */
	public function testACaseInFlightIsWrittenInsideTheScope(): void {
		$keeper = $this->keeper(register: $this->register);
		$writer = new PackageWriter(logger: new NullLogger(), settings: $this->settings, matcher: new ExistingRowMatcher(logger: new NullLogger()), states: $keeper);

		$result = $writer->writePackage(package: 'jeugdwet', groups: $this->packageRows());

		self::assertSame(expected: 'success', actual: $result['status'], message: (string)($result['message'] ?? ''));
		self::assertSame(expected: 'onderzoek', actual: $this->register->row(schema: 'case', uuid: 'c-1')['status']);
		self::assertSame(expected: 1, actual: $keeper->calls);
	}//end testACaseInFlightIsWrittenInsideTheScope()

	/**
	 * Without OpenRegister's scope the rows are written as they are, and a refusal fails the package whole.
	 *
	 * @return void
	 */
	public function testWithoutTheScopeARefusalFailsThePackage(): void {
		$writer = new PackageWriter(logger: new NullLogger(), settings: $this->settings, matcher: new ExistingRowMatcher(logger: new NullLogger()));

		$result = $writer->writePackage(package: 'jeugdwet', groups: $this->packageRows());

		self::assertSame(expected: 'error', actual: $result['status']);
		self::assertStringContainsString(needle: 'nothing it wrote was kept', haystack: (string)$result['message']);
	}//end testWithoutTheScopeARefusalFailsThePackage()
}//end class
