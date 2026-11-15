<?php

/**
 * A fan-out over untidy declarations, a destination the store does not write, and a note that does not land.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\IntakeFanOut;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Intake\IntakeFanOut
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Routing\RoutingNote
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Intake\FanOutRun
 */
class IntakeFanOutDestinationsTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Relations drawn, as "a|b" pairs.
	 *
	 * @var array<int, string>
	 */
	private array $relations = [];

	/**
	 * The fan-out over this store and this intake case type.
	 *
	 * @param object               $store    The ObjectService.
	 * @param array<string, mixed> $caseType The intake case type.
	 *
	 * @return IntakeFanOut
	 */
	private function fanOut(object $store, array $caseType = []): IntakeFanOut {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);
		$relations = $this->createMock(CaseRelationService::class);
		$relations->method('addRelation')->willReturnCallback(
			function (string $caseId, string $targetId): array {
				$this->relations[] = $caseId . '|' . $targetId;
				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new IntakeFanOut($settings, $resolver, $relations, $logger);
	}//end fanOut()

	/**
	 * A store that answers no row for one case type and refuses the note on one case.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class {
			/** @var array<string, array<string, mixed>> */
			public array $cases = [];

			/** @var array<string, array<string, mixed>> */
			public array $patches = [];

			/**
			 * @param array<string, mixed> $object   The case.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', ?string $uuid = null): mixed {
				if ($object['caseType'] === 'ct-unwritten') {
					return 'accepted';
				}

				$id = 'case-' . (count($this->cases) + 1);
				$this->cases[$id] = $object;

				return ['@self' => ['id' => $id]] + $object;
			}

			/**
			 * @param string               $objectId The case.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				if ($objectId === 'case-1') {
					throw new RuntimeException('locked');
				}

				$this->patches[$objectId] = $data;

				return $data;
			}
		};
	}//end store()

	/**
	 * Untidy entries are passed over, and a case type reference is read from an object by id or `@self.id`.
	 *
	 * @return void
	 */
	public function testDestinationsAreReadFromUntidyDeclarations(): void {
		$destinations = $this->fanOut(store: $this->store())->destinationsFor(
			caseType: [
				'intakeDestinations' => [
					'not an entry',
					['title' => 'Off', 'caseType' => 'ct-off', 'enabled' => false],
					['title' => 'Blank', 'caseType' => '   '],
					['title' => 'Empty object', 'caseType' => []],
					['title' => ' By id ', 'caseType' => ['id' => ' ct-a '], 'department' => ' kcc '],
					['title' => 'By self', 'caseType' => ['@self' => ['id' => 'ct-b']]],
				],
			]
		);

		self::assertSame(
			[
				['title' => 'By id', 'caseType' => 'ct-a', 'department' => 'kcc'],
				['title' => 'By self', 'caseType' => 'ct-b', 'department' => ''],
			],
			$destinations
		);
		self::assertSame([], $this->fanOut(store: $this->store())->destinationsFor(caseType: ['intakeDestinations' => 'ct-a']));
	}//end testDestinationsAreReadFromUntidyDeclarations()

	/**
	 * A destination the store answers no row for fails in its own words; a note that does not land is logged.
	 *
	 * @return void
	 */
	public function testAnUnwrittenDestinationFailsAndAFailedNoteIsLogged(): void {
		$store = $this->store();
		$fanOut = $this->fanOut(
			store: $store,
			caseType: [
				'intakeDestinations' => [
					['title' => 'Handhaving', 'caseType' => 'ct-handhaving', 'department' => 'handhaving'],
					['title' => 'Archief', 'caseType' => 'ct-unwritten', 'department' => 'dio'],
					['title' => 'Onderhoud', 'caseType' => 'ct-onderhoud', 'department' => 'wijkbeheer'],
				],
			]
		);

		$result = $fanOut->submit(formCaseTypeId: 'ct-form', submission: ['title' => 'Losse tegel', 'status' => 'x'], submissionId: 'sub-1');

		self::assertSame(['case-1', 'case-2'], array_column($result['created'], 'id'));
		self::assertSame(
			[['destination' => 'Archief', 'caseType' => 'ct-unwritten', 'reason' => 'The case for Archief was not written.']],
			$result['failed']
		);
		self::assertSame(['case-1|case-2'], $this->relations);
		self::assertSame(
			[
				[
					'Dossiq fan-out: destination {destination} opened no case',
					['destination' => 'Archief', 'reason' => 'The case for Archief was not written.'],
				],
				[
					'Dossiq fan-out: the routing note of case {case} was not written',
					['case' => 'case-1', 'reason' => 'locked'],
				],
			],
			$this->warnings
		);
		// The case whose note failed is not patched; the other still gets its note.
		self::assertSame(['case-2'], array_keys($store->patches));
		self::assertSame(['Handhaving'], $store->patches['case-2']['routingNote']['siblings']);
		self::assertSame(
			[['destination' => 'Archief', 'reason' => 'The case for Archief was not written.']],
			$store->patches['case-2']['routingNote']['failed']
		);
	}//end testAnUnwrittenDestinationFailsAndAFailedNoteIsLogged()
}//end class
