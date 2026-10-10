<?php

/**
 * ZrcStatusEffects Unit Tests
 *
 * Pins what a new status or resultaat does to its zaak through the ZGW API
 * (zrc-007a, zrc-007b, zrc-007q, zrc-008, zrc-021). Written against the
 * ZrcController methods before they moved (method-decomposition slice 6c) and
 * run green on both.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Zgw\ZrcEindstatus;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\Dossiq\Service\Zgw\ZrcUsageRights;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An in-memory object store with the four calls the effects make.
 */
class ZrcEffectsObjectStore {
	/** @var array<string, array<string, array<string, mixed>>> schema => uuid => row */
	public array $rows = [];

	/** @var array<int, array{schema: string, uuid: string, object: array<string, mixed>}> */
	public array $saved = [];

	/** @var array<string, bool> schemas whose buildSearchQuery() throws */
	public array $queryThrows = [];

	/** @var array<string, bool> schemas whose find() throws */
	public array $findThrows = [];

	public function find(mixed $id, mixed $register=null, mixed $schema=null): mixed {
		if (isset($this->findThrows[(string)$schema]) === true) {
			throw new \RuntimeException('find failed');
		}

		return $this->rows[(string)$schema][(string)$id] ?? null;
	}

	public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
		if (isset($this->queryThrows[(string)$schema]) === true) {
			throw new \RuntimeException('query builder failed');
		}

		unset($requestParams['_limit']);
		return ['schema' => (string)$schema, 'params' => $requestParams];
	}

	public function searchObjectsPaginated(array $query): array {
		if (isset($query['@self']) === true) {
			$schema = (string)$query['@self']['schema'];
			$params = ['caseType' => $query['caseType']];
		} else {
			$schema = $query['schema'];
			$params = $query['params'];
		}

		$hits = array_values(
			array_filter(
				($this->rows[$schema] ?? []),
				static function (array $row) use ($params): bool {
					foreach ($params as $key => $value) {
						if ((string)($row[$key] ?? '') !== (string)$value) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return ['results' => $hits, 'total' => count($hits)];
	}

	public function saveObject(mixed $register=null, mixed $schema=null, array $object=[], ?string $uuid=null): array {
		$this->saved[] = ['schema' => (string)$schema, 'uuid' => (string)$uuid, 'object' => $object];
		$this->rows[(string)$schema][(string)$uuid] = $object;
		return $object;
	}
}//end class

class ZrcStatusEffectsTest extends TestCase {
	protected const CASE = 'c0000000-0000-0000-0000-000000000001';
	protected const ZT = 'a0000000-0000-0000-0000-000000000001';
	protected const ST_FINAL = 'b0000000-0000-0000-0000-000000000003';
	protected const ST_FIRST = 'b0000000-0000-0000-0000-000000000001';
	protected const DOC_A = 'd0000000-0000-0000-0000-00000000000a';
	protected const DOC_B = 'd0000000-0000-0000-0000-00000000000b';

	/** Mapping key => schema id (digits, so ZgwSearchScope resolves them). */
	protected const SCHEMAS = [
		'case' => '11',
		'statustype' => '12',
		'zaakinformatieobject' => '13',
		'enkelvoudiginformatieobject' => '14',
		'gebruiksrechten' => '15',
	];

	protected ZrcEffectsObjectStore $store;

	/** @var array<int, string> mapping keys that answer null */
	protected array $unmapped = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = new ZrcEffectsObjectStore();
		$this->unmapped = [];
		$this->store->rows['12'] = [
			self::ST_FIRST => ['id' => self::ST_FIRST, 'caseType' => 'https://nc/catalogi/zaaktypen/' . self::ZT, 'order' => 1, 'isFinal' => false],
			self::ST_FINAL => ['id' => self::ST_FINAL, 'caseType' => self::ZT, 'order' => 3],
		];
		$this->store->rows['11'] = [
			self::CASE => ['id' => self::CASE, '@self' => ['id' => self::CASE], 'organisation' => 'o', 'identifier' => 42, 'endDate' => null],
		];
	}

	protected function zgw(): ZgwService {
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturnCallback(
			fn (string $key): ?array => (in_array($key, $this->unmapped, true) === true || isset(self::SCHEMAS[$key]) === false)
				? null
				: ['sourceRegister' => '1', 'sourceSchema' => self::SCHEMAS[$key]]
		);
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getZgwMappingService')->willReturn($mappings);
		$zgw->method('getObjectService')->willReturn($this->store);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		return $zgw;
	}

	protected function dates(): CaseDateNormaliser {
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('todayAsCalendarDate')->willReturn('2026-10-10');
		$dates->method('toCalendarDate')->willReturnCallback(static fn (mixed $v): string => substr((string)$v, 0, 10));
		$dates->method('toCalendarDateOrNull')->willReturnCallback(static fn (mixed $v): ?string => ($v === null || $v === '') ? null : substr((string)$v, 0, 10));
		return $dates;
	}

	protected function deriver(): ArchivalNominationDeriver {
		$deriver = $this->createMock(ArchivalNominationDeriver::class);
		$deriver->method('resultTypeForCase')->willReturn('rt-1');
		$deriver->method('derive')->willReturnCallback(
			static fn (array $case, string $resultTypeId, string $endDate): array => ['archiveNomination' => 'vernietigen', 'archiveActionDate' => 'from:' . $endDate]
		);
		return $deriver;
	}

	/**
	 * The object under test. A subclass may answer with another implementation of the same three calls.
	 *
	 * @return object
	 */
	protected function effects(): object {
		$zgw = $this->zgw();
		return new ZrcStatusEffects(
			zgwService: $zgw,
			dates: $this->dates(),
			archivalDeriver: $this->deriver(),
			usageRights: new ZrcUsageRights(zgwService: $zgw),
			eindstatus: new ZrcEindstatus(zgwService: $zgw),
		);
	}

	private function body(string $statustype, ?string $date='2026-03-04T10:00:00+01:00'): array {
		return ['case' => 'https://nc/zaken/' . self::CASE, 'statustype' => 'https://nc/catalogi/statustypen/' . $statustype, 'datumStatusGezet' => $date];
	}

	private function linkDocs(array $docs): void {
		foreach (array_keys($docs) as $i => $doc) {
			$this->store->rows['13']['zio-' . $i] = ['case' => self::CASE, 'document' => 'https://nc/documenten/' . $doc];
		}

		foreach ($docs as $doc => $row) {
			$this->store->rows['14'][$doc] = $row + ['id' => $doc];
		}
	}

	private function savedCase(): array {
		$cases = array_values(array_filter($this->store->saved, static fn (array $s): bool => $s['schema'] === '11'));
		$this->assertNotSame([], $cases, 'the case was not saved');
		return end($cases)['object'];
	}

	public function testTheHighestVolgnummerClosesTheCaseAndSettlesUsageRights(): void {
		$this->linkDocs([self::DOC_A => ['title' => 'a'], self::DOC_B => ['usageRightsIndication' => true]]);
		$this->store->rows['15']['gr-1'] = ['document' => self::DOC_A];

		$this->effects()->applyStatusEffect($this->body(self::ST_FINAL), []);

		$case = $this->savedCase();
		$this->assertSame('2026-03-04', $case['endDate']);
		$this->assertSame('vernietigen', $case['archiveNomination']);
		$this->assertSame('from:2026-03-04', $case['archiveActionDate']);
		$this->assertSame('42', $case['identifier']);
		$this->assertSame('', $case['title']);
		$this->assertSame(self::CASE, $case['id']);
		$this->assertArrayNotHasKey('@self', $case);
		$this->assertArrayNotHasKey('organisation', $case);
		$this->assertTrue($this->store->rows['14'][self::DOC_A]['usageRightsIndication']);
		$this->assertCount(2, $this->store->saved, 'the case and the one document without an indication');
	}

	public function testAnExplicitFlagClosesOnTodayWithoutADate(): void {
		$this->store->rows['12'][self::ST_FIRST]['isFinal'] = 'true';

		$this->effects()->applyStatusEffect($this->body(self::ST_FIRST, null), ['statusSetDate' => '']);

		$this->assertSame('2026-10-10', $this->savedCase()['endDate']);
	}

	public function testANonFinalStatusReopensAClosedCase(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';
		$this->store->rows['11'][self::CASE]['archiveNomination'] = 'blijvend_bewaren';

		$this->effects()->applyStatusEffect($this->body(self::ST_FIRST), []);

		$case = $this->savedCase();
		$this->assertNull($case['endDate']);
		$this->assertNull($case['archiveActionDate']);
		$this->assertNull($case['archiveNomination']);
	}

	public function testANonFinalStatusOnAnOpenCaseWritesNothing(): void {
		$this->effects()->applyStatusEffect($this->body(self::ST_FIRST), []);

		$this->assertSame([], $this->store->saved);
	}

	public function testAFlagStoredAsTheStringFalseReopensNothing(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';
		$this->store->rows['12'][self::ST_FIRST]['isFinal'] = 'false';

		$this->effects()->applyStatusEffect($this->body(self::ST_FIRST), []);

		$this->assertSame([], $this->store->saved);
	}

	public function testTheVolgnummerFallsBackToADirectQueryWhenTheBuilderThrows(): void {
		$this->store->queryThrows['12'] = true;

		$this->effects()->applyStatusEffect($this->body(self::ST_FINAL), []);

		$this->assertSame('2026-03-04', $this->savedCase()['endDate']);
	}

	public function testAnUnknownStatustypeOrCaseWritesNothing(): void {
		$this->effects()->applyStatusEffect($this->body('b0000000-0000-0000-0000-0000000000ff'), []);
		$this->effects()->applyStatusEffect(['case' => '', 'statustype' => 'https://x/' . self::ST_FINAL], []);
		$this->effects()->applyStatusEffect(['case' => 'not-a-uuid', 'statustype' => 'https://x/' . self::ST_FINAL], []);
		$this->unmapped = ['statustype'];
		$this->effects()->applyStatusEffect($this->body(self::ST_FINAL), []);

		$this->assertSame([], $this->store->saved);
	}

	public function testAFinalStatusIsRefusedWhileADocumentHasNoUsageRights(): void {
		$this->linkDocs([self::DOC_A => ['title' => 'a']]);
		$this->unmapped = ['gebruiksrechten'];

		$refusal = $this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FINAL));

		$this->assertSame('indicatiegebruiksrecht-unset', $refusal['code']);
		$this->assertSame('nonFieldErrors', $refusal['invalidParams'][0]['name']);
		$this->assertSame([], $this->store->saved, 'an unsearchable gebruiksrechten mapping leaves the indication unset');
	}

	public function testOnFirstCloseTheIndicationIsDerivedSoNothingIsRefused(): void {
		$this->linkDocs([self::DOC_A => ['title' => 'a']]);

		$this->assertNull($this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FINAL)));
		$this->assertFalse($this->store->rows['14'][self::DOC_A]['usageRightsIndication']);
	}

	public function testAnAlreadyClosedCaseIsCheckedWithoutDeriving(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';
		$this->linkDocs([self::DOC_A => ['usageRightsIndication' => '']]);

		$refusal = $this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FINAL));

		$this->assertSame('indicatiegebruiksrecht-unset', $refusal['code']);
		$this->assertSame([], $this->store->saved);
	}

	public function testANonFinalStatusOrAFailedLookupIsNeverRefused(): void {
		$this->linkDocs([self::DOC_A => ['title' => 'a']]);
		$this->unmapped = ['gebruiksrechten'];

		$this->assertNull($this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FIRST)));

		$this->store->findThrows['14'] = true;
		$this->assertNull($this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FINAL)));
	}

	public function testAGebruiksrechtenLookupThatFailsLeavesTheIndicationUnset(): void {
		$this->linkDocs([self::DOC_A => ['title' => 'a']]);
		$this->store->queryThrows['15'] = true;

		$refusal = $this->effects()->unsetUsageRightsRefusal($this->body(self::ST_FINAL));

		$this->assertSame('indicatiegebruiksrecht-unset', $refusal['code']);
	}

	public function testAResultDerivesTheArchiveParametersFromTheEndDate(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-05-06';

		$this->effects()->applyResultEffect(['case' => 'https://nc/zaken/' . self::CASE]);

		$case = $this->savedCase();
		$this->assertSame('from:2026-05-06', $case['archiveActionDate']);
		$this->assertSame('42', $case['identifier']);
	}

	public function testAResultOnAnOpenCaseDerivesFromToday(): void {
		$this->effects()->applyResultEffect(['case' => self::CASE]);

		$this->assertSame('from:2026-10-10', $this->savedCase()['archiveActionDate']);
	}

	public function testAResultWithoutACaseWritesNothing(): void {
		$this->effects()->applyResultEffect(['case' => '']);
		$this->unmapped = ['case'];
		$this->effects()->applyResultEffect(['case' => self::CASE]);

		$this->assertSame([], $this->store->saved);
	}

	public function testANonFinalStatusOnAClosedCaseIsAReopenAttempt(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';

		$this->assertTrue($this->effects()->isReopenAttempt($this->body(self::ST_FIRST)));
	}

	public function testOnlyTheExplicitFlagMakesAStatusFinalForTheReopenCheck(): void {
		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';
		$this->store->rows['12'][self::ST_FIRST]['isFinal'] = '1';

		$this->assertFalse($this->effects()->isReopenAttempt($this->body(self::ST_FIRST)));
		$this->assertTrue($this->effects()->isReopenAttempt($this->body(self::ST_FINAL)), 'the highest volgnummer does not count here');
	}

	public function testAnOpenCaseOrAMissingStatustypeIsNoReopenAttempt(): void {
		$this->assertFalse($this->effects()->isReopenAttempt($this->body(self::ST_FIRST)));

		$this->store->rows['11'][self::CASE]['endDate'] = '2026-01-01';
		$this->assertFalse($this->effects()->isReopenAttempt(['case' => self::CASE, 'statustype' => '']));
		$this->assertFalse($this->effects()->isReopenAttempt(['case' => self::CASE, 'statustype' => 'no-uuid']));
		$this->assertTrue($this->effects()->isReopenAttempt($this->body('b0000000-0000-0000-0000-0000000000ff')), 'an unknown type reads as not final');
	}

	public function testAnUnreadableCaseIsTreatedAsAReopenAttempt(): void {
		$this->store->findThrows['11'] = true;

		$this->assertTrue($this->effects()->isReopenAttempt($this->body(self::ST_FIRST)));
	}
}//end class
