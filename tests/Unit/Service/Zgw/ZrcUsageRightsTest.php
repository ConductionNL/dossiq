<?php

/**
 * ZrcUsageRights Unit Tests
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

use OCA\Dossiq\Service\Zgw\ZrcUsageRights;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Settling and checking indicatieGebruiksrecht on a zaak's documents.
 */
class ZrcUsageRightsTest extends TestCase {
	private const CASE = 'c0000000-0000-0000-0000-000000000001';
	private const DOC = 'd0000000-0000-0000-0000-00000000000a';

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $rows = [];

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var array<int, string> */
	private array $unmapped = [];

	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'13' => ['z1' => ['case' => self::CASE, 'document' => 'https://nc/documenten/' . self::DOC]],
			'14' => [self::DOC => ['title' => 'a']],
			'15' => [],
		];
		$this->saved = [];
		$this->unmapped = [];
	}

	private function rights(): ZrcUsageRights {
		$test = $this;
		$store = new class($test) {
			public function __construct(private ZrcUsageRightsTest $test) {
			}

			public function find(mixed $id, mixed $register=null, mixed $schema=null): mixed {
				return $this->test->rowsOf((string)$schema)[(string)$id] ?? null;
			}

			public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
				unset($requestParams['_limit']);
				return ['schema' => (string)$schema, 'params' => $requestParams];
			}

			public function searchObjectsPaginated(array $query): array {
				$key = (string)array_key_first($query['params']);
				$value = $query['params'][$key];
				return ['results' => array_values(array_filter($this->test->rowsOf($query['schema']), static fn (array $r): bool => ($r[$key] ?? null) === $value))];
			}

			public function saveObject(mixed $register=null, mixed $schema=null, array $object=[], ?string $uuid=null): array {
				$this->test->record($object);
				return $object;
			}
		};
		$schemas = ['zaakinformatieobject' => '13', 'enkelvoudiginformatieobject' => '14', 'gebruiksrechten' => '15'];
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturnCallback(
			fn (string $key): ?array => in_array($key, $this->unmapped, true) === true ? null : ['sourceRegister' => '1', 'sourceSchema' => $schemas[$key]]
		);
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getZgwMappingService')->willReturn($mappings);
		$zgw->method('getObjectService')->willReturn($store);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		return new ZrcUsageRights(zgwService: $zgw);
	}

	public function rowsOf(string $schema): array {
		return $this->rows[$schema] ?? [];
	}

	public function record(array $object): void {
		$this->saved[] = $object;
	}

	public function testClosingSetsTheIndicationFromTheGebruiksrechten(): void {
		$this->rows['15'] = ['g1' => ['document' => self::DOC]];

		$this->rights()->settleOnClose(self::CASE);

		$this->assertSame([['title' => 'a', 'usageRightsIndication' => true, 'id' => self::DOC]], $this->saved);
	}

	public function testWithoutGebruiksrechtenTheIndicationIsFalse(): void {
		$this->rights()->settleOnClose(self::CASE);

		$this->assertFalse($this->saved[0]['usageRightsIndication']);
	}

	public function testAnUnsearchableGebruiksrechtenMappingLeavesItUnsetAndTheCheckRefuses(): void {
		$this->unmapped = ['gebruiksrechten'];

		$this->rights()->settleOnClose(self::CASE);
		$refusal = $this->rights()->firstUnsetRefusal(self::CASE);

		$this->assertSame([], $this->saved);
		$this->assertSame('indicatiegebruiksrecht-unset', $refusal['code']);
	}

	public function testADocumentThatHasAnIndicationIsLeftAndPasses(): void {
		$this->rows['14'][self::DOC]['indicatieGebruiksrecht'] = false;

		$this->rights()->settleOnClose(self::CASE);

		$this->assertSame([], $this->saved);
		$this->assertNull($this->rights()->firstUnsetRefusal(self::CASE));
	}

	public function testWithoutDocumentMappingsNothingIsCheckedOrWritten(): void {
		$this->unmapped = ['enkelvoudiginformatieobject'];
		$this->assertNull($this->rights()->firstUnsetRefusal(self::CASE));
		$this->rights()->settleOnClose(self::CASE);

		$this->unmapped = ['zaakinformatieobject'];
		$this->assertNull($this->rights()->firstUnsetRefusal(self::CASE));
		$this->rights()->settleOnClose(self::CASE);

		$this->assertSame([], $this->saved);
	}
}//end class
