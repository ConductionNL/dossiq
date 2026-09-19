<?php

/**
 * A case type another app contributes actually appears.
 *
 * `CaseTypeContributionRegistry` has duck-typed a provider at the convention
 * FQCN since case-types shipped, and nothing called it. pipelinq SHIPS one:
 * `OCA\Pipelinq\Dossiq\CaseTypeContributionProvider` declares `pipelinq-ticket`.
 * So a ticket has been a declared dossiq case type for as long as both apps
 * have been installed, and the case-type list has never shown it.
 *
 * 🔴 THE CONTRIBUTOR IS REAL, WHICH IS WHY THIS IS A WIRING AND NOT A
 * RETIREMENT. A sweep of the dossiq repository alone says nothing contributes,
 * and that answer is true of the repository and false of the fleet. The seam is
 * cross-app by construction; judging it from one side of the seam is the
 * mistake it exists to make possible.
 *
 * MUTATION-CHECKED 2026-09-18, in the list at the foot of this docblock, each
 * restored after:
 *   - writing an identifier that already exists reddens
 *     testAnExistingIdentifierIsLeftExactlyAsItIs;
 *   - copying the whole declaration instead of the carried keys reddens
 *     testOnlyTheDeclaredKeysAreCarried;
 *   - letting one bad declaration throw out of the loop reddens
 *     testOneBadDeclarationDoesNotCostTheOthersTheirs.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Contribution\CaseTypeContributionRegistry;
use OCA\Dossiq\Repair\SeedContributedCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * What the upgrade does with a contributed case type.
 *
 * @covers \OCA\Dossiq\Repair\SeedContributedCaseTypes
 * @uses \OCA\Dossiq\Contribution\CaseTypeContributionRegistry
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Tests\Support\InMemoryRegister
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/case-types/spec.md
 */
class SeedContributedCaseTypesTest extends TestCase {

	/**
	 * The store the step reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An empty register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * A contributed case type is written into the case-type register.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testAContributedCaseTypeIsWritten(): void {
		$this->seedWith(
			contributed: [
				[
					'identifier' => 'pipelinq-ticket',
					'title' => 'Ticket',
					'description' => 'Customer contact handled by pipelinq.',
					'contributedBy' => 'pipelinq',
				],
			]
		);

		$stored = $this->store->all(schema: 'caseType');
		self::assertCount(expectedCount: 1, haystack: $stored);
		self::assertSame(expected: 'pipelinq-ticket', actual: $stored[0]['identifier']);
		self::assertSame(
			expected: 'pipelinq',
			actual: $stored[0]['contributedBy'],
			message: 'Who contributed it is what tells an administrator why they did not write it.',
		);
	}//end testAContributedCaseTypeIsWritten()

	/**
	 * An identifier that already exists is left exactly as it is.
	 *
	 * The assertion that makes this safe to run on every upgrade. An
	 * administrator who renamed a contributed case type, filed it under a
	 * category or set a deadline on it has said something this app must not
	 * overwrite, and this step runs every time the app is upgraded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testAnExistingIdentifierIsLeftExactlyAsItIs(): void {
		$this->store->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: [
				'id' => 'ct-1',
				'identifier' => 'pipelinq-ticket',
				'title' => 'Klantcontact',
				'category' => 'Dienstverlening',
				'contributedBy' => 'pipelinq',
			],
		);

		$contributed = [
			['identifier' => 'pipelinq-ticket', 'title' => 'Ticket', 'contributedBy' => 'pipelinq'],
		];
		$this->seedWith(contributed: $contributed);
		$this->seedWith(contributed: $contributed);

		$stored = $this->store->all(schema: 'caseType');
		self::assertCount(expectedCount: 1, haystack: $stored, message: 'Idempotent across upgrades.');
		self::assertSame(
			expected: 'Klantcontact',
			actual: $stored[0]['title'],
			message: 'The administrator renamed it. An upgrade is not the place to rename it back.',
		);
		self::assertSame(expected: 'Dienstverlening', actual: $stored[0]['category']);
	}//end testAnExistingIdentifierIsLeftExactlyAsItIs()

	/**
	 * Only the keys this app declares are carried onto the record.
	 *
	 * A provider lives in another app's repository and may carry keys that mean
	 * something there and nothing here. OpenRegister DROPS an undeclared key in
	 * silence, so copying the whole declaration would look stored and be gone,
	 * and the next person would look for the bug in the wrong app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testOnlyTheDeclaredKeysAreCarried(): void {
		$this->seedWith(
			contributed: [
				[
					'identifier' => 'pipelinq-ticket',
					'title' => 'Ticket',
					'contributedBy' => 'pipelinq',
					'discriminator' => 'ticketType',
					'assigneeProperty' => 'assignee',
					'statusProperty' => 'status',
					'register' => 'pipelinq',
					'schema' => 'ticket',
				],
			]
		);

		$stored = $this->store->all(schema: 'caseType')[0];
		foreach (['discriminator', 'assigneeProperty', 'statusProperty', 'register', 'schema'] as $key) {
			self::assertArrayNotHasKey(
				key: $key,
				array: $stored,
				message: $key . ' is not a declared caseType property, so storing it would store nothing and say so to nobody.',
			);
		}
	}//end testOnlyTheDeclaredKeysAreCarried()

	/**
	 * One app's bad declaration does not cost the others theirs.
	 *
	 * The registry already skips a provider that throws, for this reason. The
	 * same rule has to hold on the write, or the first app with a broken
	 * declaration takes every other app's case types down with it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testOneBadDeclarationDoesNotCostTheOthersTheirs(): void {
		// A store that refuses one identifier, the way OpenRegister refuses a
		// row whose value does not match its declared property. An anonymous
		// subclass rather than a method on the shared support class: only this
		// suite needs to fail a write, and a `failOn...` switch on
		// InMemoryRegister would be a fixture knob every other suite has to
		// read past.
		$this->store = new class extends InMemoryRegister {

			/**
			 * Refuse the broken declaration and store everything else.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param int|string           $register Ignored.
			 * @param int|string           $schema   The schema slug.
			 * @param string|null          $uuid     The uuid, or null to create.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
			): array {
				if (($object['identifier'] ?? '') === 'broken-type') {
					throw new RuntimeException('caseType/identifier: value is not allowed');
				}

				return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
			}
		};

		$this->seedWith(
			contributed: [
				['identifier' => 'broken-type', 'title' => 'Broken', 'contributedBy' => 'brokenapp'],
				['identifier' => 'pipelinq-ticket', 'title' => 'Ticket', 'contributedBy' => 'pipelinq'],
			]
		);

		$stored = $this->store->all(schema: 'caseType');
		self::assertCount(expectedCount: 1, haystack: $stored);
		self::assertSame(expected: 'pipelinq-ticket', actual: $stored[0]['identifier']);
	}//end testOneBadDeclarationDoesNotCostTheOthersTheirs()

	/**
	 * An instance where nothing contributes is left alone.
	 *
	 * The control. A step that invented a case type on an instance with no
	 * contributing app would put a type nobody asked for on every list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testAnInstanceWithNoContributorsIsUntouched(): void {
		$this->seedWith(contributed: []);

		self::assertSame(expected: [], actual: $this->store->all(schema: 'caseType'));
	}//end testAnInstanceWithNoContributorsIsUntouched()

	/**
	 * An unconfigured instance says so and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testAnUnconfiguredInstanceWritesNothing(): void {
		$this->seedWith(
			contributed: [['identifier' => 'pipelinq-ticket', 'title' => 'Ticket', 'contributedBy' => 'pipelinq']],
			configured: false,
		);

		self::assertSame(expected: [], actual: $this->store->all(schema: 'caseType'));
	}//end testAnUnconfiguredInstanceWritesNothing()

	/**
	 * Run the step over a registry answering these contributions.
	 *
	 * @param array<int, array<string, mixed>> $contributed What the installed apps declare.
	 * @param bool                             $configured  Whether the case-type schema resolves.
	 *
	 * @return void
	 */
	private function seedWith(array $contributed, bool $configured = true): void {
		$registry = $this->createMock(originalClassName: CaseTypeContributionRegistry::class);
		$registry->method('all')->willReturn($contributed);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($configured): string {
				if ($key === 'register') {
					return 'dossiq';
				}

				if ($key === 'case_type_schema' && $configured === true) {
					return 'caseType';
				}

				return $default;
			}
		);

		(new SeedContributedCaseTypes(
			registry: $registry,
			settingsService: $settings,
			logger: new NullLogger(),
		))->run($this->createMock(originalClassName: IOutput::class));
	}//end seedWith()
}//end class
