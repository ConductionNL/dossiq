<?php

/**
 * Applicatie Rules Test
 *
 * Pins the AC business rules an applicatie body is checked against: ac-002
 * (heeftAlleAutorisaties against the autorisaties list), ac-003 (the fields a
 * zaken, documenten or besluiten scope requires) and ac-001 (client ids
 * another applicatie already holds), in that order, first error wins.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ApplicatieRepresentation;
use OCA\Dossiq\Controller\Support\ApplicatieRules;
use OCA\Dossiq\Controller\Support\AutorisatieScopeFields;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicatieRules.
 */
#[CoversClass(ApplicatieRules::class)]
#[UsesClass(ApplicatieRepresentation::class)]
#[UsesClass(AutorisatieScopeFields::class)]
final class ApplicatieRulesTest extends TestCase {
	/**
	 * The fake mapper of the last rules() call.
	 *
	 * @var object|null
	 */
	private ?object $mapper = null;

	/**
	 * The findAll count the test expects, or null.
	 *
	 * @var int|null
	 */
	private ?int $expectedFindAlls = null;

	/**
	 * Rules over the given stored consumers, with a pass-through translator.
	 *
	 * @param array<int, object> $consumers The consumers the mapper answers.
	 * @param int|null           $findAlls  How often findAll must be called, or null for any.
	 *
	 * @return ApplicatieRules
	 */
	private function rules(array $consumers=[], ?int $findAlls=null): ApplicatieRules {
		$mapper = new class($consumers) {
			public int $calls = 0;

			/**
			 * @param array<int, object> $consumers The consumers.
			 */
			public function __construct(private array $consumers) {
			}

			/**
			 * @return array<int, object>
			 */
			public function findAll(): array {
				$this->calls++;
				return $this->consumers;
			}
		};
		$zgw = $this->createMock(ZgwServiceInterface::class);
		$zgw->method('getConsumerMapper')->willReturn($mapper);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);
		$this->mapper = $mapper;
		if ($findAlls !== null) {
			$this->expectedFindAlls = $findAlls;
		}

		return new ApplicatieRules(zgwService: $zgw, l10n: $l10n);
	}//end rules()

	/**
	 * Check the expected findAll count after each test.
	 *
	 * @return void
	 */
	protected function assertPostConditions(): void {
		if ($this->expectedFindAlls !== null) {
			self::assertSame(expected: $this->expectedFindAlls, actual: $this->mapper->calls);
		}
	}//end assertPostConditions()

	/**
	 * A stored consumer.
	 *
	 * @param string               $uuid       Its UUID.
	 * @param string               $name       Its name (the primary client id).
	 * @param array<string, mixed> $authConfig Its authorization configuration.
	 *
	 * @return object
	 */
	private function consumer(string $uuid, string $name, array $authConfig=[]): object {
		return new class($uuid, $name, $authConfig) {
			/**
			 * @param array<string, mixed> $authConfig The configuration.
			 */
			public function __construct(private string $uuid, private string $name, private array $authConfig) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => $this->uuid, 'name' => $this->name];
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->authConfig;
			}
		};
	}//end consumer()

	/**
	 * The invalidParams of a 400 response.
	 *
	 * @param JSONResponse|null $response The response.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function params(?JSONResponse $response): array {
		self::assertInstanceOf(expected: JSONResponse::class, actual: $response);
		self::assertSame(expected: 400, actual: $response->getStatus());
		return $response->getData()['invalidParams'];
	}//end params()

	/**
	 * A consistent body with free client ids passes every rule.
	 *
	 * @return void
	 */
	public function testAValidBodyPasses(): void {
		$rules = $this->rules(consumers: [$this->consumer(uuid: 'u-1', name: 'other')], findAlls: 1);

		self::assertNull(actual: $rules->validateApplicatieBody(body: ['clientIds' => ['mine'], 'heeftAlleAutorisaties' => true]));
	}//end testAValidBodyPasses()

	/**
	 * The spellings of true with a non-empty list are ambiguous (ac-002a).
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function trueSpellings(): array {
		return [
			'bool'   => [true],
			'string' => ['true'],
			'one'    => ['1'],
			'int'    => [1],
		];
	}//end trueSpellings()

	/**
	 * HeeftAlleAutorisaties true with autorisaties is refused before anything else.
	 *
	 * @param mixed $flag The flag as sent.
	 *
	 * @return void
	 */
	#[DataProvider('trueSpellings')]
	public function testAllAuthorisationsWithAListIsAmbiguous(mixed $flag): void {
		$rules = $this->rules(findAlls: 0);
		$params = $this->params(
			response: $rules->validateApplicatieBody(
				body: ['heeftAlleAutorisaties' => $flag, 'autorisaties' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]], 'clientIds' => ['x']]
			)
		);

		self::assertSame(
			expected: [[
				'name' => 'nonFieldErrors',
				'code' => 'ambiguous-authorizations-specified',
				'reason' => 'T:When heeftAlleAutorisaties is true, autorisaties must not be specified. When heeftAlleAutorisaties is false, autorisaties must be specified.',
			]],
			actual: $params
		);
	}//end testAllAuthorisationsWithAListIsAmbiguous()

	/**
	 * The spellings of false with an explicitly empty list are refused (ac-002b).
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function falseSpellings(): array {
		return [
			'bool'   => [false],
			'string' => ['false'],
			'zero'   => ['0'],
			'int'    => [0],
		];
	}//end falseSpellings()

	/**
	 * HeeftAlleAutorisaties false with an empty autorisaties list is missing authorisations.
	 *
	 * @param mixed $flag The flag as sent.
	 *
	 * @return void
	 */
	#[DataProvider('falseSpellings')]
	public function testNoAuthorisationsWithAnEmptyListIsMissing(mixed $flag): void {
		$params = $this->params(
			response: $this->rules(findAlls: 0)->validateApplicatieBody(body: ['heeftAlleAutorisaties' => $flag, 'autorisaties' => []])
		);

		self::assertSame(
			expected: [[
				'name' => 'nonFieldErrors',
				'code' => 'missing-authorizations',
				'reason' => 'T:When heeftAlleAutorisaties is false, autorisaties must be specified.',
			]],
			actual: $params
		);
	}//end testNoAuthorisationsWithAnEmptyListIsMissing()

	/**
	 * A body that leaves autorisaties out entirely is not missing them; an unknown flag spelling neither.
	 *
	 * @return void
	 */
	public function testAnAbsentListOrUnknownFlagIsNotRefused(): void {
		$rules = $this->rules();

		self::assertNull(actual: $rules->validateApplicatieBody(body: ['heeftAlleAutorisaties' => false]));
		self::assertNull(actual: $rules->validateApplicatieBody(body: ['heeftAlleAutorisaties' => 'yes', 'autorisaties' => []]));
		self::assertNull(actual: $rules->validateApplicatieBody(body: ['heeftAlleAutorisaties' => 'yes', 'autorisaties' => [['component' => 'ac']]]));
	}//end testAnAbsentListOrUnknownFlagIsNotRefused()

	/**
	 * Each scope's missing fields are named by the entry's position, in field order (ac-003).
	 *
	 * @return void
	 */
	public function testScopeRequirementsNameEveryMissingFieldByPosition(): void {
		$params = $this->params(
			response: $this->rules(findAlls: 0)->validateApplicatieBody(
				body: [
					'clientIds' => ['taken'],
					'autorisaties' => [
						['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'caseType' => '', 'maxVertrouwelijkheidaanduiding' => null],
						['component' => 'drc', 'scopes' => ['other', 'documenten.lezen'], 'maxVertrouwelijkheidaanduiding' => 'geheim'],
						['component' => 'brc', 'scopes' => ['besluiten.lezen']],
						['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'caseType' => 'ct', 'maxVertrouwelijkheidaanduiding' => 'openbaar'],
					],
				]
			)
		);

		self::assertSame(
			expected: [
				['name' => 'autorisaties.0.zaaktype', 'code' => 'required', 'reason' => 'T:zaaktype is required when a scope related to zaken is specified.'],
				['name' => 'autorisaties.0.maxVertrouwelijkheidaanduiding', 'code' => 'required', 'reason' => 'T:maxVertrouwelijkheidaanduiding is required when a scope related to zaken is specified.'],
				['name' => 'autorisaties.1.informatieobjecttype', 'code' => 'required', 'reason' => 'T:informatieobjecttype is required when a scope related to documenten is specified.'],
				['name' => 'autorisaties.2.besluittype', 'code' => 'required', 'reason' => 'T:besluittype is required when a scope related to besluiten is specified.'],
			],
			actual: $params
		);
	}//end testScopeRequirementsNameEveryMissingFieldByPosition()

	/**
	 * A scope outside the component's domain, an unknown component or a non-string scope requires nothing.
	 *
	 * @return void
	 */
	public function testScopesOutsideTheDomainRequireNothing(): void {
		$rules = $this->rules();

		self::assertNull(
			actual: $rules->validateApplicatieBody(
				body: [
					'autorisaties' => [
						['component' => 'zrc', 'scopes' => ['documenten.lezen']],
						['component' => 'ac', 'scopes' => ['zaken.lezen']],
						['component' => 'brc', 'scopes' => [42]],
						['component' => 7, 'scopes' => ['zaken']],
					],
				]
			)
		);
		self::assertNull(actual: $rules->validateApplicatieBody(body: ['autorisaties' => 'not-a-list']));
	}//end testScopesOutsideTheDomainRequireNothing()

	/**
	 * More than 100 client ids is refused without reading the consumers (ac-001).
	 *
	 * @return void
	 */
	public function testMoreThanAHundredClientIdsIsRefused(): void {
		$rules = $this->rules(findAlls: 0);
		$ids = array_map(static fn (int $i): string => "c{$i}", range(1, 101));

		self::assertSame(
			expected: [['name' => 'clientIds', 'code' => 'max-items', 'reason' => 'A maximum of 100 clientIds per applicatie is allowed.']],
			actual: $this->params(response: $rules->validateApplicatieBody(body: ['clientIds' => $ids]))
		);
	}//end testMoreThanAHundredClientIdsIsRefused()

	/**
	 * Exactly 100 free client ids are allowed.
	 *
	 * @return void
	 */
	public function testAHundredClientIdsAreAllowed(): void {
		$ids = array_map(static fn (int $i): string => "c{$i}", range(1, 100));

		self::assertNull(actual: $this->rules(findAlls: 1)->validateApplicatieBody(body: ['clientIds' => $ids]));
	}//end testAHundredClientIdsAreAllowed()

	/**
	 * The first requested id another consumer holds, by name or extra id, is named.
	 *
	 * @return void
	 */
	public function testAClientIdAnotherApplicatieHoldsIsRefused(): void {
		$rules = $this->rules(
			consumers: [
				$this->consumer(uuid: 'u-1', name: 'alpha'),
				$this->consumer(uuid: 'u-2', name: 'beta', authConfig: ['clientIds' => ['gamma', 'delta']]),
			]
		);

		self::assertSame(
			expected: [['name' => 'clientIds', 'code' => 'clientId-exists', 'reason' => 'clientId "delta" is already used by another applicatie.']],
			actual: $this->params(response: $rules->validateApplicatieBody(body: ['clientIds' => ['new', 'delta', 'gamma']]))
		);
		self::assertSame(
			expected: 'clientId "alpha" is already used by another applicatie.',
			actual: $this->params(response: $rules->validateApplicatieBody(body: ['clientIds' => ['delta', 'alpha']]))[0]['reason']
		);
	}//end testAClientIdAnotherApplicatieHoldsIsRefused()

	/**
	 * Client ids are compared strictly: an integer does not match its string.
	 *
	 * @return void
	 */
	public function testClientIdsAreComparedStrictly(): void {
		$rules = $this->rules(consumers: [$this->consumer(uuid: 'u-1', name: '12')]);

		self::assertNull(actual: $rules->validateApplicatieBody(body: ['clientIds' => [12]]));
	}//end testClientIdsAreComparedStrictly()

	/**
	 * The applicatie being updated may keep its own client ids; others still count.
	 *
	 * @return void
	 */
	public function testTheUpdatedApplicatieIsExcluded(): void {
		$rules = $this->rules(consumers: [$this->consumer(uuid: 'u-1', name: 'alpha'), $this->consumer(uuid: 'u-2', name: 'beta')]);

		self::assertNull(actual: $rules->validateApplicatieBody(body: ['clientIds' => ['alpha']], excludeUuid: 'u-1'));
		self::assertSame(
			expected: 'clientId "beta" is already used by another applicatie.',
			actual: $this->params(response: $rules->validateApplicatieBody(body: ['clientIds' => ['beta']], excludeUuid: 'u-1'))[0]['reason']
		);
	}//end testTheUpdatedApplicatieIsExcluded()

	/**
	 * No, an empty or a non-list clientIds skips the uniqueness check.
	 *
	 * @return void
	 */
	public function testNoClientIdsSkipsUniqueness(): void {
		$rules = $this->rules(consumers: [$this->consumer(uuid: 'u-1', name: 'alpha')], findAlls: 0);

		self::assertNull(actual: $rules->validateApplicatieBody(body: []));
		self::assertNull(actual: $rules->validateApplicatieBody(body: ['clientIds' => []]));
		self::assertNull(actual: $rules->validateApplicatieBody(body: ['clientIds' => 'alpha']));
	}//end testNoClientIdsSkipsUniqueness()

	/**
	 * InvalidParams wraps the params and carries the given status, 400 by default.
	 *
	 * @return void
	 */
	public function testInvalidParamsCarriesTheStatus(): void {
		$rules = $this->rules();
		$params = [['name' => 'x', 'code' => 'y', 'reason' => 'z']];

		self::assertSame(expected: 400, actual: $rules->invalidParams(params: $params)->getStatus());
		$conflict = $rules->invalidParams(params: $params, statusCode: 409);
		self::assertSame(expected: 409, actual: $conflict->getStatus());
		self::assertSame(expected: ['invalidParams' => $params], actual: $conflict->getData());
	}//end testInvalidParamsCarriesTheStatus()
}//end class
