<?php

/**
 * ZrcEindstatus Unit Tests
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

use OCA\Dossiq\Service\Zgw\ZrcEindstatus;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;

/**
 * The eindstatus answer: own flag first, else the highest volgnummer.
 */
class ZrcEindstatusTest extends TestCase {
	private const ZT = 'a0000000-0000-0000-0000-000000000001';
	private const ST1 = 'b0000000-0000-0000-0000-000000000001';
	private const ST2 = 'b0000000-0000-0000-0000-000000000002';

	/** @var array<string, array<string, mixed>> */
	private array $types = [];

	private function eindstatus(bool $mapped=true): ZrcEindstatus {
		$types = &$this->types;
		$store = new class($types) {
			public function __construct(private array &$types) {
			}

			public function find(mixed $id, mixed ...$args): mixed {
				return $this->types[(string)$id] ?? null;
			}

			public function buildSearchQuery(array $requestParams, mixed $register, mixed $schema): array {
				return $requestParams;
			}

			public function searchObjectsPaginated(array $query): array {
				return ['results' => array_values(array_filter($this->types, static fn (array $t): bool => $t['caseType'] === $query['caseType']))];
			}
		};
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturn($mapped === true ? ['sourceRegister' => '1', 'sourceSchema' => '2'] : null);
		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getZgwMappingService')->willReturn($mappings);
		$zgw->method('getObjectService')->willReturn($store);
		return new ZrcEindstatus(zgwService: $zgw);
	}

	private function body(string $st): array {
		return ['statustype' => 'https://nc/catalogi/statustypen/' . $st];
	}

	public function testAnOwnTrueFlagIsFinalInEveryStoredForm(): void {
		foreach ([true, 1, '1', 'true'] as $flag) {
			$this->types = [self::ST1 => ['caseType' => self::ZT, 'order' => 1, 'isFinalStatus' => $flag], self::ST2 => ['caseType' => self::ZT, 'order' => 2]];
			$this->assertTrue($this->eindstatus()->finalState($this->body(self::ST1)));
			$this->assertTrue($this->eindstatus()->explicitlyFinal($this->body(self::ST1)));
		}
	}

	public function testWithoutAFlagTheHighestVolgnummerIsFinal(): void {
		$this->types = [self::ST1 => ['caseType' => self::ZT, 'order' => 1, 'isFinal' => false], self::ST2 => ['caseType' => self::ZT, 'sequenceNumber' => 2]];

		$this->assertTrue($this->eindstatus()->finalState($this->body(self::ST2)));
		$this->assertFalse($this->eindstatus()->explicitlyFinal($this->body(self::ST2)), 'the own flag alone says not final');
		$this->assertFalse($this->eindstatus()->finalState($this->body(self::ST1)));
	}

	public function testAnOtherStoredFlagIsNeitherAndAMissingTypeIsNone(): void {
		$this->types = [self::ST1 => ['caseType' => self::ZT, 'order' => 1, 'isEindstatus' => 'no'], self::ST2 => ['caseType' => self::ZT, 'order' => 2]];

		$this->assertNull($this->eindstatus()->finalState($this->body(self::ST1)));
		$this->assertSame('none', $this->eindstatus()->finalState($this->body('b0000000-0000-0000-0000-0000000000ff')));
		$this->assertSame('none', $this->eindstatus(mapped: false)->finalState($this->body(self::ST1)));
		$this->assertNull($this->eindstatus(mapped: false)->explicitlyFinal($this->body(self::ST1)));
		$this->assertFalse($this->eindstatus()->explicitlyFinal($this->body('b0000000-0000-0000-0000-0000000000ff')));
	}
}//end class
