<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Dutch -> English `direction` value migration.
 *
 * The arms that matter are the EXEMPTION arms. This step rewrites the string
 * `intern`, and `intern` is also a value of the statutory ZGW
 * `vertrouwelijkheidaanduiding` enum. What keeps the two apart is that the
 * update is scoped to the `direction` COLUMN — so the tests pin the column
 * scope and the exact value set, not just the happy-path mapping.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Repair
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\RenameDutchDirectionValues;
use OCA\Procest\Service\ZgwRulesBase;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for RenameDutchDirectionValues.
 *
 * @covers \OCA\Procest\Repair\RenameDutchDirectionValues
 */
class RenameDutchDirectionValuesTest extends TestCase {

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return RenameDutchDirectionValues
	 */
	private function step(): RenameDutchDirectionValues {
		return new RenameDutchDirectionValues(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end step()


	/**
	 * Read a private constant.
	 *
	 * @param string $name The constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchDirectionValues::class))->getConstant($name);

	}//end constant()


	/**
	 * Call a private method.
	 *
	 * @param string            $name The method name.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$method = (new ReflectionClass(RenameDutchDirectionValues::class))->getMethod($name);
		$method->setAccessible(true);
		return $method->invokeArgs($this->step(), $args);

	}//end call()


	/**
	 * The three Dutch values map to their English equivalents.
	 *
	 * @return void
	 */
	public function testTheValueMapCoversTheThreeDutchDirections(): void {
		$this->assertSame(
			['inkomend' => 'inbound', 'uitgaand' => 'outbound', 'intern' => 'internal'],
			$this->constant('VALUE_MAP')
		);

	}//end testTheValueMapCoversTheThreeDutchDirections()


	/**
	 * THE EXEMPTION ARM — the step touches only the `direction` column.
	 *
	 * If this ever widens, the same `intern` rewrite lands on
	 * `vertrouwelijkheidaanduiding` and corrupts a statutory ZGW value on every
	 * row that carries one.
	 *
	 * @return void
	 */
	public function testItTouchesOnlyTheDirectionColumn(): void {
		$this->assertSame('direction', $this->constant('COLUMN'));

	}//end testItTouchesOnlyTheDirectionColumn()


	/**
	 * THE EXEMPTION ARM, from the other side — every statutory
	 * vertrouwelijkheidaanduiding except `intern` is absent from the map, and
	 * `intern` is present ONLY because it is a direction too.
	 *
	 * This is what makes the column scope load-bearing rather than incidental:
	 * the two vocabularies genuinely overlap on one word.
	 *
	 * @return void
	 */
	public function testTheStatutoryConfidentialityValuesAreNotRewritten(): void {
		$map = $this->constant('VALUE_MAP');
		$levels = (new ReflectionClass(ZgwRulesBase::class))->getConstant('VERTROUWELIJKHEID_LEVELS');

		$overlap = array_intersect(array_keys($map), array_keys($levels));

		// One word overlaps, and it is the reason the column scope exists.
		$this->assertSame(['intern'], array_values($overlap));

		foreach (array_keys($levels) as $level) {
			if ($level === 'intern') {
				continue;
			}

			$this->assertArrayNotHasKey(
				$level,
				$map,
				sprintf('statutory ZGW confidentiality value "%s" must never be rewritten', $level)
			);
		}

	}//end testTheStatutoryConfidentialityValuesAreNotRewritten()


	/**
	 * Both procest registers are in scope — resolving one slug would migrate
	 * half the rows and report success.
	 *
	 * @return void
	 */
	public function testBothProcestRegistersAreInScope(): void {
		$this->assertSame('procest', $this->constant('REGISTER_SLUG_PREFIX'));

		$markers = ['openregister_table_17_', 'openregister_table_2424_'];

		$this->assertTrue($this->call('isShardOf', ['oc_openregister_table_17_928', $markers]));
		$this->assertTrue($this->call('isShardOf', ['oc_openregister_table_2424_928', $markers]));

	}//end testBothProcestRegistersAreInScope()


	/**
	 * Another app's shard table is out of scope.
	 *
	 * Measured: 105 shard tables on the dev install carry a `direction` column,
	 * across pipelinq, decidesk, shillinq, scholiq and openconnector. Their
	 * vocabularies are their own.
	 *
	 * @return void
	 */
	public function testAnotherAppsShardTableIsNotMatched(): void {
		$markers = ['openregister_table_17_', 'openregister_table_2424_'];

		// 16 = pipelinq, 264 = shillinq, 65 = openconnector.
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_16_41', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_264_41', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_65_5114', $markers]));

	}//end testAnotherAppsShardTableIsNotMatched()


	/**
	 * A register id that merely starts with an in-scope id is not a match.
	 *
	 * `openregister_table_17_` must not match register 170 or 1700.
	 *
	 * @return void
	 */
	public function testALongerRegisterIdIsNotMatched(): void {
		$markers = ['openregister_table_17_'];

		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_170_928', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_1700_928', $markers]));

	}//end testALongerRegisterIdIsNotMatched()


	/**
	 * Values outside the map are left alone.
	 *
	 * portaalBericht stores `citizen_to_handler` in its `direction` column —
	 * a different vocabulary that happens to share the column name.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedDirectionVocabularyIsNotRewritten(): void {
		$map = $this->constant('VALUE_MAP');

		$this->assertArrayNotHasKey('citizen_to_handler', $map);
		$this->assertArrayNotHasKey('handler_to_citizen', $map);

	}//end testAnUnrecognisedDirectionVocabularyIsNotRewritten()


	/**
	 * The step is idempotent by construction: no English target is also a
	 * source, so a second run maps nothing.
	 *
	 * @return void
	 */
	public function testTheMigrationIsIdempotentByConstruction(): void {
		$map = $this->constant('VALUE_MAP');

		foreach ($map as $old => $new) {
			$this->assertArrayNotHasKey(
				$new,
				$map,
				sprintf('"%s" is both a target and a source, so a re-run would rewrite it again', $new)
			);
			$this->assertNotSame($old, $new);
		}

	}//end testTheMigrationIsIdempotentByConstruction()


}//end class
