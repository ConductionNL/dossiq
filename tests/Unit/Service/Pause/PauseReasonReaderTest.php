<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pause
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pause;

use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which pause reasons a case type declares, and which one a case or pause names.
 *
 * @covers \OCA\Dossiq\Service\Pause\PauseReasonReader
 * @uses   \OCA\Dossiq\Service\Pause\PauseReason
 */
class PauseReasonReaderTest extends TestCase {
	/**
	 * Case types the store holds, by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseTypes = [];

	/**
	 * Lookups the store received.
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	public array $finds = [];

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The term declaration reader.
	 *
	 * @var TermDeclarationReader&MockObject
	 */
	private TermDeclarationReader&MockObject $declarations;

	/**
	 * Set up a configured store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$this->configure($this->store(), ['register' => 'dossiq', 'case_type_schema' => 'caseType']);
	}

	/**
	 * Configure the object service and app config the reader sees.
	 *
	 * @param object|null           $store  The object service.
	 * @param array<string, string> $config App config values.
	 *
	 * @return void
	 */
	private function configure(?object $store, array $config): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
	}

	/**
	 * A store that answers from $this->caseTypes.
	 *
	 * @return object The store.
	 */
	private function store(): object {
		return new class($this) {
			/**
			 * @param PauseReasonReaderTest $test The test, for the case types and lookups.
			 */
			public function __construct(private PauseReasonReaderTest $test) {
			}

			/**
			 * @param string $id       The case type id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed The case type.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				$this->test->finds[] = [$id, $register, $schema];
				return $this->test->caseType($id);
			}
		};
	}

	/**
	 * The stored case type for an id, or a miss.
	 *
	 * @param string $id The id.
	 *
	 * @return mixed The case type.
	 */
	public function caseType(string $id): mixed {
		if (array_key_exists($id, $this->caseTypes) === false) {
			throw new DoesNotExistException('not found');
		}

		$row = $this->caseTypes[$id];
		if ($row instanceof \Throwable) {
			throw $row;
		}

		return $row;
	}

	/**
	 * The reader under test.
	 *
	 * @return PauseReasonReader
	 */
	private function reader(): PauseReasonReader {
		return new PauseReasonReader($this->settings, $this->declarations);
	}

	/**
	 * Declared reasons are normalised; non-arrays and keyless reasons are dropped.
	 *
	 * @return void
	 */
	public function testReasonsAreNormalisedAndKeylessOnesDropped(): void {
		$reasons = $this->reader()->reasonsIn([
			'pauseReasons' => [
				['key' => ' docs ', 'name' => 'Missing documents', 'category' => 'nonsense', 'chaseBudget' => 2],
				'not a reason',
				['key' => '   ', 'name' => 'Nameless'],
				['key' => 'advice', 'name' => 'External advice', 'category' => 'third-party'],
			],
		]);

		self::assertSame(['docs', 'advice'], array_column($reasons, 'key'));
		self::assertSame('Missing documents', $reasons[0]['name']);
		self::assertSame(2, $reasons[0]['chaseBudget']);
		self::assertSame([], $this->reader()->reasonsIn([]));
		self::assertSame([], $this->reader()->reasonsIn(['pauseReasons' => 'none']));
	}

	/**
	 * A case type is read from the configured register and schema.
	 *
	 * @return void
	 */
	public function testACaseTypeIsReadFromTheConfiguredStore(): void {
		$this->caseTypes = ['ct-1' => ['pauseReasons' => [['key' => 'docs']]]];

		self::assertSame(['docs'], array_column($this->reader()->forCaseType('ct-1'), 'key'));
		self::assertSame([['ct-1', 'dossiq', 'caseType']], $this->finds);
	}

	/**
	 * A missing case type, an entity that serialises, and a non-array answer.
	 *
	 * @return void
	 */
	public function testStoreAnswersAreReadAsRowsOrNothing(): void {
		$entity          = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed> The case type.
			 */
			public function jsonSerialize(): array {
				return ['pauseReasons' => [['key' => 'entity']]];
			}
		};
		$this->caseTypes = ['entity' => $entity, 'scalar' => 'text'];

		self::assertSame([], $this->reader()->forCaseType('gone'));
		self::assertSame(['entity'], array_column($this->reader()->forCaseType('entity'), 'key'));
		self::assertSame([], $this->reader()->forCaseType('scalar'));
	}

	/**
	 * Without a store, a case type id, a register or a schema nothing is asked.
	 *
	 * @return void
	 */
	public function testAnIncompleteConfigurationAsksNothing(): void {
		$this->caseTypes = ['ct-1' => ['pauseReasons' => [['key' => 'docs']]]];

		self::assertSame([], $this->reader()->forCaseType(''));
		$this->configure(null, ['register' => 'dossiq', 'case_type_schema' => 'caseType']);
		self::assertSame([], $this->reader()->forCaseType('ct-1'));
		$this->configure($this->store(), ['register' => 'dossiq']);
		self::assertSame([], $this->reader()->forCaseType('ct-1'));
		$this->configure($this->store(), ['case_type_schema' => 'caseType']);
		self::assertSame([], $this->reader()->forCaseType('ct-1'));
		self::assertSame([], $this->finds);
	}

	/**
	 * A store failure other than a miss is not turned into "no reasons".
	 *
	 * @return void
	 */
	public function testAStoreFailurePropagates(): void {
		$this->caseTypes = ['ct-1' => new RuntimeException('database unavailable')];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('database unavailable');

		$this->reader()->forCaseType('ct-1');
	}

	/**
	 * A case's reasons come from its case type; a case without one has none.
	 *
	 * @return void
	 */
	public function testACasesReasonsComeFromItsCaseType(): void {
		$this->caseTypes = ['ct-1' => ['pauseReasons' => [['key' => 'docs'], ['key' => 'advice']]]];
		$this->declarations->method('caseContext')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'case-1') {
					return ['caseType' => 'ct-1'];
				}

				return ['caseType' => ''];
			}
		);

		self::assertSame(['docs', 'advice'], array_column($this->reader()->forCase('case-1'), 'key'));
		self::assertSame([], $this->reader()->caseTypeForCase('case-2'));
		self::assertSame([], $this->reader()->forCase('case-2'));
	}

	/**
	 * A reason is found by its trimmed key; blank or unknown keys find nothing.
	 *
	 * @return void
	 */
	public function testAReasonIsFoundByKey(): void {
		$this->caseTypes = ['ct-1' => ['pauseReasons' => [['key' => 'docs', 'name' => 'Missing documents'], ['key' => 'advice']]]];
		$this->declarations->method('caseContext')->willReturn(['caseType' => 'ct-1']);

		self::assertSame('Missing documents', $this->reader()->findForCase('case-1', ' docs ')['name']);
		self::assertNull($this->reader()->findForCase('case-1', 'other'));
		self::assertNull($this->reader()->findForCase('case-1', '  '));
	}

	/**
	 * A pause names its reason and its case; without either there is no reason in force.
	 *
	 * @return void
	 */
	public function testAPauseNamesItsReason(): void {
		$this->caseTypes = ['ct-1' => ['pauseReasons' => [['key' => 'docs']]]];
		$this->declarations->method('caseContext')->with('case-1')->willReturn(['caseType' => 'ct-1']);

		self::assertSame('docs', $this->reader()->forInstance(['pauseReason' => ' docs', 'case' => ' case-1 '])['key']);
		self::assertNull($this->reader()->forInstance(['pauseReason' => 'docs']));
		self::assertNull($this->reader()->forInstance(['case' => 'case-1']));
		self::assertNull($this->reader()->forInstance(['pauseReason' => 'docs', 'case' => '']));
	}
}
