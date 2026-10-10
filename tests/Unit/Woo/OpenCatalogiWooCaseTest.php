<?php

/**
 * Every opencatalogi Woo request status lands on its Woo case stage, with
 * its term state, deadline and old reference carried.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\OpenCatalogiWooCase;
use OCA\Dossiq\Woo\WooRequestRefused;
use PHPUnit\Framework\TestCase;

/**
 * Runs the mapping over opencatalogi's own request shape (tests/Fixtures/opencatalogi-woo-requests.json).
 *
 * @covers \OCA\Dossiq\Woo\OpenCatalogiWooCase
 * @uses   \OCA\Dossiq\Woo\WooReceivedAnswers
 * @uses   \OCA\Dossiq\Woo\WooRequestForm
 * @uses   \OCA\Dossiq\Woo\WooRequestRefused
 */
class OpenCatalogiWooCaseTest extends TestCase {

	/**
	 * The fixture rows, keyed by status.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function sources(): array {
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/Fixtures/opencatalogi-woo-requests.json'), true);
		$byStatus = [];
		foreach ($fixture['requests'] as $row) {
			$byStatus[$row['status']] = $row;
		}

		return $byStatus;
	}//end sources()

	/**
	 * All five statuses map to their stage, open or closed, suspended or not.
	 *
	 * @return void
	 */
	public function testEveryStatusMapsToItsStage(): void {
		$expected = [
			'received' => ['3c0f5a00-0000-4000-a000-00000000b001', true, false, ''],
			'in_progress' => ['3c0f5a00-0000-4000-a000-00000000b004', true, false, ''],
			'awaiting_clarification' => ['3c0f5a00-0000-4000-a000-00000000b002', true, true, ''],
			'decided' => ['3c0f5a00-0000-4000-a000-00000000b008', false, false, ''],
			'withdrawn' => ['3c0f5a00-0000-4000-a000-00000000b008', false, false, OpenCatalogiWooCase::RESULT_WITHDRAWN],
		];

		$sources = $this->sources();
		self::assertEqualsCanonicalizing(array_keys($expected), array_keys($sources));
		foreach ($expected as $status => [$stage, $open, $suspended, $result]) {
			$mapped = (new OpenCatalogiWooCase())->fromSource(source: $sources[$status], sourceUuid: $sources[$status]['id']);
			self::assertSame($stage, $mapped['case']['status'], $status);
			self::assertSame($open, $mapped['open'], $status);
			self::assertSame($suspended, $mapped['suspended'], $status);
			self::assertSame($result, $mapped['result'], $status);
		}
	}//end testEveryStatusMapsToItsStage()

	/**
	 * A running request keeps its start, deadline, requester, channel and old reference.
	 *
	 * @return void
	 */
	public function testARunningRequestCarriesItsStartDeadlineAndReference(): void {
		$source = $this->sources()['received'];
		$mapped = (new OpenCatalogiWooCase())->fromSource(source: $source, sourceUuid: $source['id']);
		$case = $mapped['case'];

		self::assertSame('2026-03-02', $case['startDate']);
		self::assertSame('2026-03-02T09:00:00+00:00', $case['receivedAt']);
		self::assertSame('2026-03-30', $mapped['deadline']);
		self::assertSame('website', $case['intakeChannel']);
		self::assertSame('opencatalogi', $case['wooRequest']['origin']);
		self::assertSame($source['id'], $case['wooRequest']['originReference']);
		self::assertSame('Sanne de Groot', $case['wooRequest']['verzoekerNaam']);
		self::assertSame([['application' => 'opencatalogi', 'reference' => 'WOO-2026-0001']], $case['formerReferences']);
		self::assertSame(0, $case['extensionCount']);
		self::assertArrayNotHasKey('portalSubject', $case);
	}//end testARunningRequestCarriesItsStartDeadlineAndReference()

	/**
	 * An extended, suspended request keeps its extension count; a decided one its end date.
	 *
	 * @return void
	 */
	public function testAnExtendedSuspendedRequestKeepsBothAndADecidedOneItsEndDate(): void {
		$sources = $this->sources();
		$suspended = (new OpenCatalogiWooCase())->fromSource(source: $sources['awaiting_clarification'], sourceUuid: 'u4');
		self::assertSame(1, $suspended['case']['extensionCount']);
		self::assertSame('2026-11-23', $suspended['deadline']);

		$decided = (new OpenCatalogiWooCase())->fromSource(source: $sources['decided'], sourceUuid: 'u3');
		self::assertSame('2026-02-05', $decided['case']['endDate']);
		self::assertSame('post', $decided['case']['intakeChannel']);
		self::assertSame('Postbus 1234, 1000 AA Amsterdam', $decided['case']['wooRequest']['verzoekerAdres']);
	}//end testAnExtendedSuspendedRequestKeepsBothAndADecidedOneItsEndDate()

	/**
	 * Every mapped case fits the merged case schema.
	 *
	 * @return void
	 */
	public function testEveryMappedCaseFitsTheCaseSchema(): void {
		$real = new RealSchemaValidator();
		foreach ($this->sources() as $status => $source) {
			$case = (new OpenCatalogiWooCase())->fromSource(source: $source, sourceUuid: $source['id'])['case'];
			self::assertSame([], $real->errors(slug: 'case', payload: $case), $status . ' ' . json_encode($case));
		}
	}//end testEveryMappedCaseFitsTheCaseSchema()

	/**
	 * A row with an unknown status or no receipt moment is refused.
	 *
	 * @return void
	 */
	public function testAnUnusableRowIsRefused(): void {
		$source = $this->sources()['received'];
		foreach ([['status' => 'archived'], ['receivedAt' => ''], ['requestedInformation' => '']] as $broken) {
			try {
				(new OpenCatalogiWooCase())->fromSource(source: array_merge($source, $broken), sourceUuid: 'u1');
				self::fail('Accepted ' . json_encode($broken));
			} catch (WooRequestRefused $e) {
				self::assertSame(WooRequestRefused::INVALID, $e->getReason());
			}
		}
	}//end testAnUnusableRowIsRefused()
}//end class
