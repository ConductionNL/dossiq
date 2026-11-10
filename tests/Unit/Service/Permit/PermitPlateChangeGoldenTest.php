<?php

/**
 * Golden test for opening a plate change case on a held permit.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Permit
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Permit;

use ArrayObject;
use OCA\Dossiq\Service\Permit\PermitChangeRefused;
use OCA\Dossiq\Service\Permit\PermitPlateChange;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every read, search and save of a plate change, and which resident it runs for.
 *
 * @covers \OCA\Dossiq\Service\Permit\PermitPlateChange
 * @covers \OCA\Dossiq\Service\Permit\PermitChangeRefused
 * @covers \OCA\Dossiq\Service\Permit\PermitRegister
 * @covers \OCA\Dossiq\Service\Permit\PlateChange
 * @covers \OCA\Dossiq\Service\Permit\PlateChangeRequest
 */
class PermitPlateChangeGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const ACTING = 'subj-sanne';

	/**
	 * The register as the tests start it.
	 *
	 * @return array<string, array<string, mixed>> Objects by schema and id.
	 */
	private static function world(): array {
		return [
			'permit' => [
				'p1' => ['id' => 'p1', 'title' => 'Bewonersvergunning', 'portalSubject' => self::ACTING, 'status' => 'active', 'case' => 'c1'],
				'p-bob' => ['id' => 'p-bob', 'title' => 'Van Bob', 'portalSubject' => 'subj-bob', 'portalParty' => 'subject:' . self::ACTING, 'status' => 'active', 'case' => 'c1'],
				'p-upper' => ['id' => 'p-upper', 'title' => 'Hoofdletters', 'portalSubject' => 'SUBJ-SANNE', 'status' => 'active', 'case' => 'c1'],
				'p-nobody' => ['id' => 'p-nobody', 'title' => 'Zonder houder', 'status' => 'active', 'case' => 'c1'],
				'p-revoked' => ['id' => 'p-revoked', 'title' => 'Oud', 'portalSubject' => self::ACTING, 'status' => 'revoked', 'case' => 'c1'],
				'p-nocase' => ['id' => 'p-nocase', 'title' => 'Los', 'portalSubject' => self::ACTING, 'status' => 'active'],
				'p-untyped' => ['id' => 'p-untyped', 'title' => 'Geen type', 'portalSubject' => self::ACTING, 'status' => 'active', 'case' => 'c-untyped'],
			],
			'case' => [
				'c1' => ['id' => 'c1', 'caseType' => 'ct1'],
				'c-untyped' => ['id' => 'c-untyped', 'caseType' => 'ct-plain'],
			],
			'caseType' => [
				'ct1' => ['id' => 'ct1', 'issuesPermit' => ['changeCaseType' => 'ct2']],
				'ct-plain' => ['id' => 'ct-plain'],
				'ct2' => ['id' => 'ct2', 'initialStatus' => ' st-new '],
			],
			'propdef' => [
				'd1' => ['id' => 'd1', 'caseType' => 'ct2', 'name' => 'permit'],
				'd2' => ['@self' => ['id' => 'd2'], 'caseType' => 'ct2', 'name' => ' nieuwKenteken '],
				'd3' => ['id' => 'd3', 'caseType' => 'ct2', 'name' => 'iets anders'],
				'd4' => ['id' => '', 'caseType' => 'ct2', 'name' => 'permit'],
				'd5' => ['id' => 'd5', 'caseType' => 'ct9', 'name' => 'permit'],
			],
		];
	}//end world()

	/**
	 * Run one request and answer its result plus every call it made.
	 *
	 * @param array<int, string>   $input  Subject, permit id and plate.
	 * @param array<string, mixed> $config Overrides of the world, the store and the settings.
	 *
	 * @return array<string, mixed> The outcome and the trace.
	 */
	private function observe(array $input, array $config = []): array {
		$trace = new ArrayObject();
		$world = self::world();
		foreach (($config['patch'] ?? []) as $path => $value) {
			[$schema, $id, $field] = explode('.', $path);
			if ($value === null) {
				unset($world[$schema][$id][$field]);
				continue;
			}

			$world[$schema][$id][$field] = $value;
		}

		$store = new class($world, $config, $trace) {
			/**
			 * @param array<string, array<string, mixed>> $world  Objects by schema and id.
			 * @param array<string, mixed>                $config The store's behaviour.
			 * @param ArrayObject                         $trace  Every call.
			 */
			public function __construct(private array $world, private array $config, private ArrayObject $trace) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it answers.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->trace->append(['runAsSystem']);
				return $operation();
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed> The object.
			 *
			 * @throws DoesNotExistException When there is none.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				$this->trace->append(['find', $register, $schema, $id]);
				if (($this->config['readThrows'] ?? '') === $schema) {
					throw new RuntimeException('database gone');
				}

				if (isset($this->world[$schema][$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->world[$schema][$id];
			}

			/**
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether tenancy applies.
			 *
			 * @return array<int, array<string, mixed>> The matching objects.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->trace->append(['search', $register, $schema, $filters, $_rbac, $_multitenancy]);
				return array_values(
					array_filter(
						($this->world[$schema] ?? []),
						static fn (array $row): bool => ($row['caseType'] ?? null) === ($filters['caseType'] ?? '')
					)
				);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The id to update.
			 *
			 * @return array<string, mixed>|null The stored object.
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): ?array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				if (($object['startDate'] ?? null) === date('Y-m-d')) {
					$object['startDate'] = '<today>';
				}

				$this->trace->append(['save', $register, $schema, $uuid, $object]);
				$answer = ($this->config['save'] ?? 'id');
				if ($answer === 'throw') {
					throw new RuntimeException('save refused');
				}

				return match ($answer) {
					'id' => array_merge($object, ['id' => 'case-new', 'identifier' => '2026-0042']),
					'self' => array_merge($object, ['@self' => ['id' => 'case-self']]),
					'none' => $object,
				};
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($config['service'] ?? true) === true ? $store : null);
		$values = array_merge(['register' => 'reg-1', 'case_schema' => 'case-s', 'property_definition_schema' => 'propdef'], ($config['settings'] ?? []));
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		try {
			$outcome = ['answer' => (new PermitPlateChange(settingsService: $settings))->request(...$input)];
		} catch (PermitChangeRefused $refused) {
			$outcome = ['refused' => $refused->getReason(), 'detail' => $refused->getDetail()];
		}

		return array_merge($outcome, ['calls' => $trace->getArrayCopy()]);
	}//end observe()

	/**
	 * Every path through request(), recorded on the code before the split.
	 *
	 * @return void
	 */
	public function testGoldenPlateChange(): void {
		$sanne = [self::ACTING, 'p1'];
		$observed = [
			'a new plate' => $this->observe([...$sanne, 'hx-901-b']),
			'a plate with spaces' => $this->observe([...$sanne, ' gz 482 k ']),
			'a plate too long' => $this->observe([...$sanne, 'GZ-482-KXX']),
			'a plate with a sign' => $this->observe([...$sanne, 'GZ482!']),
			'no subject' => $this->observe(['  ', 'p1', 'HX901B']),
			'no permit id' => $this->observe([self::ACTING, ' ', 'HX901B']),
			'no object service' => $this->observe([...$sanne, 'HX901B'], ['service' => false]),
			'no register' => $this->observe([...$sanne, 'HX901B'], ['settings' => ['register' => '']]),
			'another resident asks for this resident\'s permit' => $this->observe(['subj-bob', 'p1', 'HX901B']),
			'this resident asks for another resident\'s permit' => $this->observe([self::ACTING, 'p-bob', 'HX901B']),
			'a holder differing in case' => $this->observe([self::ACTING, 'p-upper', 'HX901B']),
			'a subject with a trailing space' => $this->observe([self::ACTING . ' ', 'p1', 'HX901B']),
			'a permit without a holder' => $this->observe([self::ACTING, 'p-nobody', 'HX901B']),
			'no such permit' => $this->observe([self::ACTING, 'p-404', 'HX901B']),
			'a revoked permit' => $this->observe([self::ACTING, 'p-revoked', 'HX901B']),
			'a permit without a case' => $this->observe([self::ACTING, 'p-nocase', 'HX901B']),
			'a case type naming no change type' => $this->observe([self::ACTING, 'p-untyped', 'HX901B']),
			'a change type that does not exist' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['caseType.ct1.issuesPermit' => ['changeCaseType' => 'ct-404']]]),
			'a change type without an initial status' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['caseType.ct2.initialStatus' => null]]),
			'a blank initial status' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['caseType.ct2.initialStatus' => '  ']]),
			'a change type without an id' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['caseType.ct2.id' => null]]),
			'no property definition schema' => $this->observe([...$sanne, 'HX901B'], ['settings' => ['property_definition_schema' => '']]),
			'no case schema configured' => $this->observe([...$sanne, 'HX901B'], ['settings' => ['case_schema' => null]]),
			'a permit naming another resident as its party' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['permit.p1.portalParty' => 'subject:subj-bob']]),
			'a permit without a title' => $this->observe([...$sanne, 'HX901B'], ['patch' => ['permit.p1.title' => null]]),
			'the permit read fails' => $this->observe([...$sanne, 'HX901B'], ['readThrows' => 'permit']),
			'the case type read fails' => $this->observe([...$sanne, 'HX901B'], ['readThrows' => 'caseType']),
			'the save fails' => $this->observe([...$sanne, 'HX901B'], ['save' => 'throw']),
			'the save answers an @self id' => $this->observe([...$sanne, 'HX901B'], ['save' => 'self']),
			'the save answers no id' => $this->observe([...$sanne, 'HX901B'], ['save' => 'none']),
		];

		$this->assertMatchesGolden('permit-plate-change', $observed);
	}//end testGoldenPlateChange()
}//end class
