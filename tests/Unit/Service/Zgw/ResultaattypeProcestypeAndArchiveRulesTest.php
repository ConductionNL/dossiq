<?php

/**
 * The resultaattype create rules past enrichment: the procestype of the
 * selectielijstklasse must match the zaaktype's (ztc-002e), the
 * brondatumArchiefprocedure cross-field errors are reported (ztc-003..008),
 * and the per-request context reaches the brondatum validator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\BrondatumArchiefValidator;
use OCA\Dossiq\Service\Zgw\ZgwZtcResultaattypeRules;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZgwZtcResultaattypeRules
 * @uses   \OCA\Dossiq\Service\ZgwRulesBase
 * @uses   \OCA\Dossiq\Service\FieldValidator
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses   \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class ResultaattypeProcestypeAndArchiveRulesTest extends TestCase {

	private const SELECTIE = 'https://selectielijst.example/klassen/1';

	private const ZAAKTYPE_UUID = '6f1c2a8e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

	private const ZAAKTYPE = 'https://ztc.example/zaaktypen/' . self::ZAAKTYPE_UUID;

	/**
	 * The zaaktype lookups made, as [uuid, schemaKey].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $lookups = [];

	/**
	 * The brondatum validator.
	 *
	 * @var BrondatumArchiefValidator&MockObject
	 */
	private BrondatumArchiefValidator&MockObject $brondatum;

	/**
	 * Set up the brondatum validator double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->brondatum = $this->createMock(BrondatumArchiefValidator::class);
	}//end setUp()

	/**
	 * The rules over these external answers and stored zaaktypen.
	 *
	 * @param array<string, array>      $answers   External URL answers.
	 * @param array<string, array|null> $zaaktypen Stored zaaktypen by uuid.
	 * @param bool                      $context   Whether an object service is set.
	 *
	 * @return ZgwZtcResultaattypeRules
	 */
	private function rules(array $answers, array $zaaktypen=[], bool $context=true): ZgwZtcResultaattypeRules {
		$rules = new class(new NullLogger(), $this->createMock(SettingsService::class), new FieldValidator(), $this->brondatum, $answers, $zaaktypen, $this) extends ZgwZtcResultaattypeRules {
			/**
			 * @param mixed                               $logger    Logger.
			 * @param mixed                               $settings  Settings.
			 * @param mixed                               $validator Field validator.
			 * @param mixed                               $brondatum Brondatum validator.
			 * @param array                               $answers   External answers.
			 * @param array                               $zaaktypen Stored zaaktypen.
			 * @param ResultaattypeProcestypeAndArchiveRulesTest $test The test.
			 */
			public function __construct($logger, $settings, $validator, $brondatum, private array $answers, private array $zaaktypen, private ResultaattypeProcestypeAndArchiveRulesTest $test) {
				parent::__construct($logger, $settings, $validator, $brondatum);
			}

			/**
			 * @param string $url The URL.
			 *
			 * @return array|null
			 */
			protected function fetchExternalUrl(string $url): ?array {
				return ($this->answers[$url] ?? null);
			}

			/**
			 * @param string $uuid      The uuid.
			 * @param string $schemaKey The schema key.
			 *
			 * @return array|null
			 */
			protected function findBySchemaKey(string $uuid, string $schemaKey): ?array {
				$this->test->lookups[] = [$uuid, $schemaKey];
				return ($this->zaaktypen[$uuid] ?? null);
			}
		};

		if ($context === true) {
			$rules->setContext(objectService: new \stdClass(), mappingConfig: ['m' => 1]);
		}

		return $rules;
	}//end rules()

	/**
	 * The per-request context reaches the brondatum validator unchanged.
	 *
	 * @return void
	 */
	public function testTheContextReachesTheBrondatumValidator(): void {
		$objectService = new \stdClass();
		$this->brondatum->expects($this->once())->method('setContext')->with($objectService, ['m' => 2]);

		$this->rules([], [], false)->setContext(objectService: $objectService, mappingConfig: ['m' => 2]);
	}//end testTheContextReachesTheBrondatumValidator()

	/**
	 * A selectielijstklasse whose procestype differs from the zaaktype's is refused.
	 *
	 * @return void
	 */
	public function testAProcestypeMismatchIsRefused(): void {
		$this->brondatum->expects($this->never())->method('validate');
		$rules = $this->rules(
			[self::SELECTIE => ['procesType' => 'https://selectielijst.example/procestypen/2']],
			[self::ZAAKTYPE_UUID => ['selectionListProcessType' => 'https://selectielijst.example/procestypen/1']]
		);

		$result = $rules->rulesResultaattypenCreate(
			['selectielijstklasse' => self::SELECTIE, 'caseType' => self::ZAAKTYPE, 'brondatumArchiefprocedure' => ['afleidingswijze' => 'afgehandeld']]
		);

		$detail = 'Het procestype van de selectielijstklasse komt niet overeen met het procestype van het zaaktype.';
		self::assertFalse($result['valid']);
		self::assertSame(400, $result['status']);
		self::assertSame($detail, $result['detail']);
		self::assertSame([['name' => 'nonFieldErrors', 'code' => 'procestype-mismatch', 'reason' => $detail]], $result['invalidParams']);
		self::assertSame([[self::ZAAKTYPE_UUID, 'case_type_schema']], $this->lookups);
	}//end testAProcestypeMismatchIsRefused()

	/**
	 * A matching procestype passes.
	 *
	 * @return void
	 */
	public function testAMatchingProcestypePasses(): void {
		$procestype = 'https://selectielijst.example/procestypen/1';
		$rules = $this->rules([self::SELECTIE => ['procesType' => $procestype]], [self::ZAAKTYPE_UUID => ['selectionListProcessType' => $procestype]]);

		$result = $rules->rulesResultaattypenCreate(['selectielijstklasse' => self::SELECTIE, 'caseType' => self::ZAAKTYPE]);

		self::assertTrue($result['valid']);
		self::assertSame(1, count($this->lookups));
	}//end testAMatchingProcestypePasses()

	/**
	 * The procestype check is skipped without what it needs.
	 *
	 * @return array<string, array{0: array, 1: array, 2: bool, 3: int}>
	 */
	public static function skippedProcestypeChecks(): array {
		$selectie = [self::SELECTIE => ['procesType' => 'p-1']];
		$body = ['selectielijstklasse' => self::SELECTIE, 'caseType' => self::ZAAKTYPE];

		return [
			'no zaaktype in the body'      => [$selectie, ['selectielijstklasse' => self::SELECTIE], true, 0],
			'no object service'            => [$selectie, $body, false, 0],
			'zaaktype url without a uuid'  => [$selectie, ['selectielijstklasse' => self::SELECTIE, 'caseType' => 'https://ztc.example/zaaktypen/abc'], true, 0],
			'zaaktype not found'           => [$selectie, $body, true, 1],
			'selectielijst lacks one'      => [[self::SELECTIE => ['other' => 'x']], $body, true, 1],
			'no selectielijstklasse given' => [[], ['caseType' => self::ZAAKTYPE], true, 0],
		];
	}//end skippedProcestypeChecks()

	/**
	 * Each missing input skips the procestype check and the body passes.
	 *
	 * @param array $answers The external answers.
	 * @param array $body    The body.
	 * @param bool  $context Whether an object service is set.
	 * @param int   $lookups How many zaaktype lookups are expected.
	 *
	 * @return void
	 *
	 * @dataProvider skippedProcestypeChecks
	 */
	public function testTheProcestypeCheckIsSkippedWithoutItsInputs(array $answers, array $body, bool $context, int $lookups): void {
		$zaaktypen = [];
		if ($lookups === 1 && isset($answers[self::SELECTIE]['other']) === true) {
			$zaaktypen = [self::ZAAKTYPE_UUID => ['selectionListProcessType' => 'p-1']];
		}

		$result = $this->rules($answers, $zaaktypen, $context)->rulesResultaattypenCreate($body);

		self::assertTrue($result['valid']);
		self::assertCount($lookups, $this->lookups);
	}//end testTheProcestypeCheckIsSkippedWithoutItsInputs()

	/**
	 * A zaaktype without a procestype skips the comparison.
	 *
	 * @return void
	 */
	public function testAZaaktypeWithoutProcestypeSkipsTheComparison(): void {
		$rules = $this->rules([self::SELECTIE => ['procesType' => 'p-1']], [self::ZAAKTYPE_UUID => ['selectionListProcessType' => '']]);

		self::assertTrue($rules->rulesResultaattypenCreate(['selectielijstklasse' => self::SELECTIE, 'caseType' => self::ZAAKTYPE])['valid']);
	}//end testAZaaktypeWithoutProcestypeSkipsTheComparison()

	/**
	 * The brondatum archive procedure is validated with the selectielijstklasse,
	 * and its first error is the problem detail.
	 *
	 * @return void
	 */
	public function testBrondatumErrorsAreReported(): void {
		$archive = ['afleidingswijze' => 'termijn'];
		$selectie = ['procestermijn' => 'nihil'];
		$errors = [
			['name' => 'brondatumArchiefprocedure.afleidingswijze', 'code' => 'invalid-afleidingswijze-for-procestermijn', 'reason' => 'Ongeldige afleidingswijze.'],
			['name' => 'brondatumArchiefprocedure.procestermijn', 'code' => 'required', 'reason' => 'Verplicht.'],
		];
		$this->brondatum->expects($this->once())->method('validate')->with($archive, $selectie)->willReturn($errors);

		$result = $this->rules([self::SELECTIE => $selectie])->rulesResultaattypenCreate(
			['selectielijstklasse' => self::SELECTIE, 'brondatumArchiefprocedure' => $archive]
		);

		self::assertFalse($result['valid']);
		self::assertSame(400, $result['status']);
		self::assertSame('Ongeldige afleidingswijze.', $result['detail']);
		self::assertSame($errors, $result['invalidParams']);
	}//end testBrondatumErrorsAreReported()

	/**
	 * An archive procedure without a selectielijstklasse is validated against none.
	 *
	 * @return void
	 */
	public function testBrondatumWithoutSelectielijstIsValidatedAgainstNone(): void {
		$archive = ['afleidingswijze' => 'afgehandeld'];
		$this->brondatum->expects($this->once())->method('validate')->with($archive, null)->willReturn([]);

		$result = $this->rules([])->rulesResultaattypenCreate(['brondatumArchiefprocedure' => $archive]);

		self::assertTrue($result['valid']);
		self::assertSame(['brondatumArchiefprocedure' => $archive], $result['enrichedBody']);
	}//end testBrondatumWithoutSelectielijstIsValidatedAgainstNone()
}//end class
