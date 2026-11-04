<?php

/**
 * Golden test for reporting and placing the backfilled legal holds.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command\Backfill
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command\Backfill;

use ArrayObject;
use OCA\Dossiq\Command\Backfill\LegalHoldApplier;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pins every lookup, hold check, hold placed and output line of the legal-hold backfill.
 *
 * @covers \OCA\Dossiq\Command\Backfill\LegalHoldApplier
 * @covers \OCA\Dossiq\Command\Backfill\HoldRun
 */
class LegalHoldApplierGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The candidate cases every run walks, keyed by UUID.
	 *
	 * @return array<string|int, array<string, mixed>>
	 */
	private static function candidates(): array {
		return [
			'c-free' => ['count' => 1, 'schemas' => ['bezwaar']],
			'c-held' => ['count' => 2, 'schemas' => ['bezwaar', 'beroep']],
			'c-missing' => ['count' => 1, 'schemas' => ['beroep']],
			'c-throws' => ['count' => 1, 'schemas' => []],
			'c-scalar' => ['count' => 3, 'schemas' => ['bezwaar']],
			'c-refused' => ['schemas' => ['bezwaar']],
			'c-deleted' => ['count' => 1],
			7 => ['count' => 1, 'schemas' => ['beroep']],
			'c-two' => ['count' => 2, 'schemas' => ['bezwaar', 'beroep']],
		];
	}//end candidates()

	/**
	 * Run one backfill and answer the exit code, the output and every call.
	 *
	 * @param bool                                    $apply          Whether to write.
	 * @param bool                                    $includeDeleted Whether soft-deleted cases are in scope.
	 * @param array<string|int, array<string, mixed>> $candidates     The candidates.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(bool $apply, bool $includeDeleted, array $candidates): array {
		$trace = new ArrayObject();
		$mapper = new class($trace) {
			/**
			 * @param ArrayObject $trace Every call.
			 */
			public function __construct(private ArrayObject $trace) {
			}

			/**
			 * @param string $identifier     The UUID.
			 * @param bool   $includeDeleted Whether soft-deleted objects count.
			 * @param bool   $_rbac          Whether RBAC applies.
			 * @param bool   $_multitenancy  Whether tenancy applies.
			 *
			 * @return mixed The object.
			 */
			public function find(string $identifier, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->trace->append(['find', $identifier, $includeDeleted, $_rbac, $_multitenancy]);
				return match ($identifier) {
					'c-missing' => null,
					'c-throws' => throw new RuntimeException('no such object'),
					'c-scalar' => 'not an object',
					'c-deleted' => ($includeDeleted === true ? (object)['id' => $identifier] : null),
					default => (object)['id' => $identifier],
				};
			}
		};
		$holds = new class($trace) {
			/**
			 * @param ArrayObject $trace Every call.
			 */
			public function __construct(private ArrayObject $trace) {
			}

			/**
			 * @param object $object The case.
			 *
			 * @return bool Whether it carries an active hold.
			 */
			public function hasActiveHold(object $object): bool {
				$this->trace->append(['hasActiveHold', $object->id]);
				return $object->id === 'c-held';
			}

			/**
			 * @param object $object The case.
			 * @param string $reason The reason.
			 *
			 * @return void
			 */
			public function placeHold(object $object, string $reason): void {
				$this->trace->append(['placeHold', $object->id, $reason]);
				if ($object->id === 'c-refused') {
					throw new RuntimeException('hold refused');
				}
			}
		};

		$lines = new ArrayObject();
		$output = $this->createMock(OutputInterface::class);
		$output->method('writeln')->willReturnCallback(static function (string|iterable $message) use ($lines): void {
			$lines->append($message);
		});
		$code = (new LegalHoldApplier())->reportAndApply(
			candidates: $candidates,
			objectMapper: $mapper,
			legalHoldService: $holds,
			apply: $apply,
			includeDeleted: $includeDeleted,
			output: $output
		);

		return ['code' => $code, 'output' => $lines->getArrayCopy(), 'calls' => $trace->getArrayCopy()];
	}//end observe()

	/**
	 * Every path through reportAndApply(), recorded on the code before the split.
	 *
	 * @return void
	 */
	public function testGoldenBackfill(): void {
		$observed = [
			'a dry run' => $this->observe(apply: false, includeDeleted: false, candidates: self::candidates()),
			'an applied run' => $this->observe(apply: true, includeDeleted: false, candidates: self::candidates()),
			'an applied run with deleted cases' => $this->observe(apply: true, includeDeleted: true, candidates: self::candidates()),
			'a dry run with deleted cases' => $this->observe(apply: false, includeDeleted: true, candidates: self::candidates()),
			'no candidates' => $this->observe(apply: true, includeDeleted: false, candidates: []),
		];

		$this->assertMatchesGolden('legal-hold-applier', $observed);
	}//end testGoldenBackfill()
}//end class
