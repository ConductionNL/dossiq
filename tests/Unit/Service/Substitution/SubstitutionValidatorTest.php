<?php

/**
 * The rules that make a substitution acceptable
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
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

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutionValidator;
use OCA\Dossiq\Service\SubstitutionRequest;
use PHPUnit\Framework\TestCase;

/**
 * Identity, enum, period and scope-ref validation, and the full-scope overlap invariant.
 *
 * @covers \OCA\Dossiq\Service\Substitution\SubstitutionValidator
 * @uses   \OCA\Dossiq\Service\SubstitutionRequest
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class SubstitutionValidatorTest extends TestCase {
	/**
	 * The searches made against the store, as [register, schema, filters].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $searches = [];

	/**
	 * A validator over a store that answers the given substitution rows.
	 *
	 * @param array<int, array<string, mixed>> $rows      The stored substitutions.
	 * @param bool                             $noObjects Whether OpenRegister is absent.
	 *
	 * @return SubstitutionValidator
	 */
	private function validator(array $rows = [], bool $noObjects = false): SubstitutionValidator {
		$test = $this;
		$store = new class ($test, $rows) {
			/**
			 * Constructor.
			 *
			 * @param SubstitutionValidatorTest         $test The test.
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private SubstitutionValidatorTest $test, private array $rows) {
			}

			/**
			 * The stored rows.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->searches[] = [$register, $schema, $filters];

				return $this->rows;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noObjects ? null : $store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'substitution_schema' => 'substitution'][$key] ?? ''
		);

		return new SubstitutionValidator(settingsService: $settings);
	}//end validator()

	/**
	 * A well-formed request.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return SubstitutionRequest
	 */
	private function request(array $overrides = []): SubstitutionRequest {
		$request = new SubstitutionRequest();
		$request->absentee = 'anna';
		$request->substitute = 'bram';
		$request->startDate = '2026-03-01';
		$request->endDate = '2026-03-14';
		foreach ($overrides as $field => $value) {
			$request->$field = $value;
		}

		return $request;
	}//end request()

	/**
	 * A valid request resolves to its midnight-anchored inclusive period.
	 *
	 * @return void
	 */
	public function testAValidRequestResolvesItsPeriod(): void {
		[$start, $end] = $this->validator()->validateCreate($this->request());

		$this->assertSame('2026-03-01 00:00:00', $start->format('Y-m-d H:i:s'));
		$this->assertSame('2026-03-14 00:00:00', $end->format('Y-m-d H:i:s'));
	}//end testAValidRequestResolvesItsPeriod()

	/**
	 * A one-day substitution (start equals end) is accepted.
	 *
	 * @return void
	 */
	public function testASingleDayPeriodIsAccepted(): void {
		[$start, $end] = $this->validator()->validateCreate($this->request(['endDate' => '2026-03-01']));

		$this->assertEquals($start, $end);
	}//end testASingleDayPeriodIsAccepted()

	/**
	 * Each invalid request is refused with its own message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalidRequests(): array {
		return [
			'no absentee'          => [['absentee' => ''], 'Both absentee and substitute are required'],
			'no substitute'        => [['substitute' => ''], 'Both absentee and substitute are required'],
			'self-substitution'    => [['substitute' => 'anna'], 'A handler cannot be their own waarnemer (self-substitution is not allowed)'],
			'unknown scope'        => [['scope' => 'everything'], 'Invalid scope; expected all, caseTypes or cases'],
			'unknown reason'       => [['reason' => 'vakantie'], 'Invalid reason; expected verlof, ziekte or anders'],
			'no start date'        => [['startDate' => ''], 'A valid startDate (YYYY-MM-DD) is required'],
			'impossible start'     => [['startDate' => '2026-02-30'], 'A valid startDate (YYYY-MM-DD) is required'],
			'open-ended'           => [['endDate' => ''], 'A valid endDate (YYYY-MM-DD) is required; open-ended absences must be re-issued or converted to a bulk reassignment'],
			'inverted range'       => [['endDate' => '2026-02-28'], 'endDate must not be before startDate'],
			'caseTypes, no refs'   => [['scope' => 'caseTypes'], 'scopeRefs is required when scope is caseTypes or cases'],
			'cases, no refs'       => [['scope' => 'cases'], 'scopeRefs is required when scope is caseTypes or cases'],
		];
	}//end invalidRequests()

	/**
	 * An invalid request throws with the rule's message.
	 *
	 * @param array<string, mixed> $overrides The invalid fields.
	 * @param string               $message   The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider invalidRequests
	 */
	public function testAnInvalidRequestIsRefused(array $overrides, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->validator()->validateCreate($this->request($overrides));
	}//end testAnInvalidRequestIsRefused()

	/**
	 * Identity is checked before the enums: a self-substitution with a bad scope reports the identity.
	 *
	 * @return void
	 */
	public function testIdentityIsCheckedBeforeEnums(): void {
		$this->expectExceptionMessage('A handler cannot be their own waarnemer (self-substitution is not allowed)');

		$this->validator()->validateCreate($this->request(['substitute' => 'anna', 'scope' => 'bogus']));
	}//end testIdentityIsCheckedBeforeEnums()

	/**
	 * A narrowed scope with refs is accepted.
	 *
	 * @return void
	 */
	public function testANarrowedScopeWithRefsIsAccepted(): void {
		$period = $this->validator()->validateCreate($this->request(['scope' => 'cases', 'scopeRefs' => ['case-1'], 'reason' => 'ziekte']));

		$this->assertCount(2, $period);
	}//end testANarrowedScopeWithRefsIsAccepted()

	/**
	 * Dates parse with a trailing time part ignored; garbage and out-of-range parts are null.
	 *
	 * @return void
	 */
	public function testParseDate(): void {
		$validator = $this->validator();

		$this->assertSame('2026-01-05', $validator->parseDate(' 2026-1-5T10:00:00Z ')?->format('Y-m-d'));
		$this->assertSame('00:00:00', $validator->parseDate('2026-01-05 13:45')?->format('H:i:s'));
		$this->assertNull($validator->parseDate(''));
		$this->assertNull($validator->parseDate('05-01-2026'));
		$this->assertNull($validator->parseDate('2026-13-01'));
		$this->assertNull($validator->parseDate('2025-02-29'));
		$this->assertSame('2024-02-29', $validator->parseDate('2024-02-29')?->format('Y-m-d'));
	}//end testParseDate()

	/**
	 * A narrowed new substitution never conflicts and does not read the store.
	 *
	 * @return void
	 */
	public function testANarrowedScopeSkipsTheOverlapCheck(): void {
		$validator = $this->validator([['id' => 's1', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-03-01', 'endDate' => '2026-03-31']]);

		$validator->assertNoOverlappingFullScope('anna', 'cases', new DateTimeImmutable('2026-03-05'), new DateTimeImmutable('2026-03-06'));

		$this->assertSame([], $this->searches);
	}//end testANarrowedScopeSkipsTheOverlapCheck()

	/**
	 * An active full-scope overlap is refused, naming the conflicting id, after searching by absentee.
	 *
	 * @return void
	 */
	public function testAnActiveFullScopeOverlapIsRefused(): void {
		$validator = $this->validator([
			['id' => 's0', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-01-01', 'endDate' => '2026-01-31'],
			['uuid' => 'u-9', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-03-14', 'endDate' => '2026-03-20'],
		]);

		try {
			$validator->assertNoOverlappingFullScope('anna', 'all', new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-14'));
			$this->fail('Expected the overlap to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'An active full-scope substitution already covers this handler for an overlapping period (conflicting substitution: u-9)',
				$e->getMessage()
			);
		}

		$this->assertSame([['dossiq', 'substitution', ['absentee' => 'anna']]], $this->searches);
	}//end testAnActiveFullScopeOverlapIsRefused()

	/**
	 * Rows that are inactive, narrowed, undated or disjoint do not conflict.
	 *
	 * @return void
	 */
	public function testNonConflictingRowsAreAllowed(): void {
		$validator = $this->validator([
			['id' => 'a', 'status' => 'ended', 'scope' => 'all', 'startDate' => '2026-03-01', 'endDate' => '2026-03-31'],
			['id' => 'b', 'status' => 'active', 'scope' => 'caseTypes', 'startDate' => '2026-03-01', 'endDate' => '2026-03-31'],
			['id' => 'c', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-03-01'],
			['id' => 'd', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-02-01', 'endDate' => '2026-02-28'],
			['id' => 'e', 'status' => 'active', 'scope' => 'all', 'startDate' => '2026-03-15', 'endDate' => '2026-03-31'],
		]);

		$validator->assertNoOverlappingFullScope('anna', 'all', new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-14'));

		$this->assertCount(1, $this->searches);
	}//end testNonConflictingRowsAreAllowed()

	/**
	 * Without OpenRegister the overlap check passes without reading anything.
	 *
	 * @return void
	 */
	public function testNoObjectServiceSkipsTheOverlapCheck(): void {
		$this->validator([], true)->assertNoOverlappingFullScope('anna', 'all', new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-14'));

		$this->assertSame([], $this->searches);
	}//end testNoObjectServiceSkipsTheOverlapCheck()
}//end class
