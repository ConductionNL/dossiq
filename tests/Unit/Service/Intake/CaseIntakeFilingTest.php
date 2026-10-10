<?php

/**
 * Unit tests for CaseIntakeFiling: the conversational intake files through the
 * create form's own write, and refuses what that write refuses.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\CaseIntakeFiling;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for CaseIntakeFiling.
 */
class CaseIntakeFilingTest extends TestCase {

	/**
	 * Every saveObject call the object service double received.
	 *
	 * @var list<array{object: array<string, mixed>, register: string, schema: string}>
	 */
	private array $saves = [];

	private CaseTypeStore&MockObject $caseTypes;

	/**
	 * What the double's saveObject throws, when set.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $saveRefusal = null;

	protected function setUp(): void {
		$this->saves = [];
		$this->saveRefusal = null;
		$this->caseTypes = $this->createMock(CaseTypeStore::class);
	}//end setUp()

	/**
	 * Build the filing over an in-memory object service.
	 *
	 * @param bool $storage Whether the object service resolves.
	 *
	 * @return CaseIntakeFiling The filing.
	 */
	private function filing(bool $storage = true): CaseIntakeFiling {
		$test = $this;
		$objectService = new class($test) {
			/**
			 * @param CaseIntakeFilingTest $test The test holding the write log.
			 */
			public function __construct(private CaseIntakeFilingTest $test) {
			}//end __construct()

			/**
			 * Record the write, or refuse it like a stopped ObjectCreatingEvent.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				return $this->test->recordSave(object: $object, register: $register, schema: $schema);
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($storage === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		return new CaseIntakeFiling(settingsService: $settings, caseTypes: $this->caseTypes, logger: new NullLogger());
	}//end filing()

	/**
	 * The double's write, kept on the test so the anonymous class can reach it.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function recordSave(array $object, string $register, string $schema): array {
		if ($this->saveRefusal !== null) {
			throw $this->saveRefusal;
		}

		$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];

		return $object + ['id' => 'case-new', 'identifier' => 'Z-2026-0001'];
	}//end recordSave()

	/**
	 * A published case type answering to its uuid.
	 *
	 * @return void
	 */
	private function publishedType(): void {
		$this->caseTypes->method('readCaseType')->willReturnCallback(
			static fn (string $id): array => ($id === 'type-1' ? ['id' => 'type-1', 'isDraft' => false, 'identifier' => 'melding'] : [])
		);
		$this->caseTypes->method('versionsWithIdentifier')->willReturnCallback(
			static fn (string $identifier): array => ($identifier === 'melding'
				? [['id' => 'type-0', 'isDraft' => true], ['id' => 'type-1', 'isDraft' => false]]
				: [])
		);
	}//end publishedType()

	/**
	 * The transcript a conversation hands over.
	 *
	 * @return array<int, array<string, string>> The messages.
	 */
	private function messages(): array {
		return [
			['channel' => 'web', 'text' => 'De lantaarnpaal voor nummer 12 is kapot.', 'at' => '2026-10-10T10:00:00+02:00'],
			['channel' => 'web', 'text' => '  ', 'at' => '2026-10-10T10:01:00+02:00'],
			['channel' => 'email', 'text' => 'Hij knippert al een week.', 'at' => '2026-10-10T10:02:00+02:00'],
		];
	}//end messages()

	public function testTheCaseIsWrittenOnceToTheCaseSchemaAsTheCreateFormWritesIt(): void {
		$this->publishedType();

		$case = $this->filing()->file(type: 'type-1', subject: 'Kapotte lantaarnpaal', person: 'jan@example.nl', messages: $this->messages());

		$this->assertCount(1, $this->saves);
		$this->assertSame('dossiq', $this->saves[0]['register']);
		$this->assertSame('case', $this->saves[0]['schema']);
		$this->assertSame(
			[
				'title' => 'Kapotte lantaarnpaal',
				'caseType' => 'type-1',
				'intakeChannel' => 'website',
				'description' => "[web] De lantaarnpaal voor nummer 12 is kapot.\n[email] Hij knippert al een week.",
				'initiatorDisplayName' => 'jan@example.nl',
			],
			$this->saves[0]['object']
		);
		$this->assertSame('case-new', $case['id']);
	}//end testTheCaseIsWrittenOnceToTheCaseSchemaAsTheCreateFormWritesIt()

	public function testTheCaseTypeIsFoundByIdentifierAndADraftIsPassedOver(): void {
		$this->publishedType();

		$this->filing()->file(type: 'melding', subject: 'Kapotte lantaarnpaal', person: '', messages: []);

		$this->assertSame('type-1', $this->saves[0]['object']['caseType']);
		$this->assertArrayNotHasKey('initiatorDisplayName', $this->saves[0]['object']);
		$this->assertArrayNotHasKey('description', $this->saves[0]['object']);
	}//end testTheCaseTypeIsFoundByIdentifierAndADraftIsPassedOver()

	public function testThePayloadFitsTheRealCaseSchema(): void {
		$payload = $this->filing()->payload(caseTypeId: 'type-1', subject: str_repeat('a', 300), person: 'Jan', messages: $this->messages());

		$register = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/dossiq_register.json'), true);
		$schema = $register['components']['schemas']['case'];
		foreach ($schema['required'] as $required) {
			$this->assertArrayHasKey($required, $payload, 'the case schema requires ' . $required);
		}

		foreach ($payload as $key => $value) {
			$this->assertArrayHasKey($key, $schema['properties'], 'case schema has no property ' . $key);
			$this->assertSame('string', $schema['properties'][$key]['type']);
			$this->assertIsString($value);
			if (isset($schema['properties'][$key]['enum']) === true) {
				$this->assertContains($value, $schema['properties'][$key]['enum'], $key);
			}

			if (isset($schema['properties'][$key]['maxLength']) === true) {
				$this->assertLessThanOrEqual($schema['properties'][$key]['maxLength'], mb_strlen($value), $key);
			}
		}
	}//end testThePayloadFitsTheRealCaseSchema()

	public function testAMissingSubjectIsRefusedAndNothingIsWritten(): void {
		$this->publishedType();

		try {
			$this->filing()->file(type: 'type-1', subject: '   ', person: 'Jan', messages: []);
			$this->fail('a case without a subject must be refused');
		} catch (RefusedException $e) {
			$this->assertSame('intake-subject-missing', $e->getRule());
		}

		$this->assertSame([], $this->saves);
	}//end testAMissingSubjectIsRefusedAndNothingIsWritten()

	public function testAnUnknownOrDraftOnlyCaseTypeIsRefusedAndNothingIsWritten(): void {
		$this->caseTypes->method('readCaseType')->willReturn([]);
		$this->caseTypes->method('versionsWithIdentifier')->willReturn([['id' => 'type-0', 'isDraft' => true]]);

		try {
			$this->filing()->file(type: 'concept-type', subject: 'Iets', person: '', messages: []);
			$this->fail('a draft-only case type must be refused');
		} catch (RefusedException $e) {
			$this->assertSame('intake-case-type-unknown', $e->getRule());
			$this->assertStringContainsString('concept-type', $e->getSentence());
		}

		$this->assertSame([], $this->saves);
	}//end testAnUnknownOrDraftOnlyCaseTypeIsRefusedAndNothingIsWritten()

	public function testWhatTheCreatePathRefusesIsRefusedInItsOwnWords(): void {
		$this->publishedType();
		$this->saveRefusal = new RefusedException(
			rule: 'intake-requirements',
			sentence: 'This case type needs the communication channel before the case can exist.'
		);

		try {
			$this->filing()->file(type: 'type-1', subject: 'Kapotte lantaarnpaal', person: '', messages: []);
			$this->fail('a refusal from the create path must be a refusal here');
		} catch (RefusedException $e) {
			$this->assertSame('intake-refused-by-create-path', $e->getRule());
			$this->assertStringContainsString('needs the communication channel', $e->getSentence());
		}

		$this->saveRefusal = new RuntimeException('Not allowed to create objects in this schema');
		try {
			$this->filing()->file(type: 'type-1', subject: 'Kapotte lantaarnpaal', person: '', messages: []);
			$this->fail('an RBAC refusal must be a refusal here');
		} catch (RefusedException $e) {
			$this->assertStringContainsString('Not allowed to create objects', $e->getSentence());
		}

		$this->assertSame([], $this->saves);
	}//end testWhatTheCreatePathRefusesIsRefusedInItsOwnWords()

	public function testNoStorageFilesNothing(): void {
		$this->publishedType();

		$this->expectException(RefusedException::class);
		$this->filing(storage: false)->file(type: 'type-1', subject: 'Iets', person: '', messages: []);
	}//end testNoStorageFilesNothing()
}//end class
