<?php

/**
 * ZgwRulesBase::checkFieldUniqueness characterisation (method-decomposition slice 7).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwRulesBase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which stored second-field values count as the same combination.
 */
class ZgwRulesBaseUniquenessTest extends TestCase {
	/** @var array<int, mixed> */
	private array $hits = [];

	private bool $throws = false;

	private function check(string $field2Value, ?array $mapping=['sourceRegister' => '1', 'sourceSchema' => '2'], string $field1Value='ID-1'): ?array {
		$test = $this;
		$store = new class($test) {
			public function __construct(private ZgwRulesBaseUniquenessTest $test) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				return $this->test->answer($query);
			}
		};
		$rules = new class($this->createMock(LoggerInterface::class), $this->createMock(SettingsService::class), $this->createMock(FieldValidator::class)) extends ZgwRulesBase {
			public function unique(string $a, string $b): ?array {
				return $this->checkFieldUniqueness(field1Value: $a, field1Search: 'identifier', field2Value: $b, field2Search: 'sourceOrganisation', errorField: 'identificatie');
			}
		};
		$rules->setContext($store, $mapping);
		return $rules->unique($field1Value, $field2Value);
	}

	public function answer(array $query): array {
		if ($this->throws === true) {
			throw new \RuntimeException('down');
		}

		$this->assertSame(['register' => 1, 'schema' => 2], $query['@self']);
		$this->assertSame('ID-1', $query['identifier']);
		return ['results' => $this->hits];
	}

	public function testTheSameOrganisationIsADuplicate(): void {
		$this->hits = [['sourceOrganisation' => '123456789']];

		$this->assertSame('identificatie-niet-uniek', $this->check('123456789')['invalidParams'][0]['code']);
	}

	public function testAnotherOrganisationIsUnique(): void {
		$this->hits = [['sourceOrganisation' => '999']];

		$this->assertNull($this->check('123456789'));
	}

	public function testCoercedStoredValuesCountAsTheSame(): void {
		$this->hits = [['sourceOrganisation' => '']];
		$this->assertNotNull($this->check('123'));

		$this->hits = [['sourceOrganisation' => 0]];
		$this->assertNotNull($this->check('000000000'));
		$this->assertNull($this->check('100'));

		$this->hits = [new class {
			public function jsonSerialize(): array {
				return ['sourceOrganisation' => 'x'];
			}
		}];
		$this->assertNotNull($this->check(''), 'no second value matches any hit');
	}

	public function testNothingToCheckOrAFailedSearchIsUnique(): void {
		$this->hits = [['sourceOrganisation' => 'a']];
		$this->assertNull($this->check('a', field1Value: ''));
		$this->assertNull($this->check('a', mapping: ['sourceRegister' => '', 'sourceSchema' => '2']));
		$this->assertNull($this->check('a', mapping: null));

		$this->throws = true;
		$this->assertNull($this->check('a'));
	}
}//end class
