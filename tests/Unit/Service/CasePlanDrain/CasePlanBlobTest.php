<?php

/**
 * Blob reader tests: decode, stamp states, build history, verify.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CasePlanDrain
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
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CasePlanDrain;

use OCA\Dossiq\Service\CasePlanDrain\CasePlanBlob;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for {@see CasePlanBlob}.
 *
 * @covers \OCA\Dossiq\Service\CasePlanDrain\CasePlanBlob
 */
final class CasePlanBlobTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @var CasePlanBlob
	 */
	private CasePlanBlob $blob;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->blob = new CasePlanBlob();
	}//end setUp()

	/**
	 * No blob, an empty string and an empty array all mean "nothing to drain".
	 *
	 * @return void
	 */
	public function testAnAbsentBlobDecodesToNull(): void {
		$this->assertNull($this->blob->decode(raw: null));
		$this->assertNull($this->blob->decode(raw: ''));
		$this->assertNull($this->blob->decode(raw: []));
	}//end testAnAbsentBlobDecodesToNull()

	/**
	 * The engine stored the blob encoded; OpenRegister may hand it back decoded.
	 *
	 * @return void
	 */
	public function testBothStoredShapesDecodeAlike(): void {
		$blob = ['planItemStates' => ['intake' => 'active'], 'caseFile' => ['x' => 1], 'eventLog' => []];

		$this->assertSame($this->blob->decode(raw: $blob), $this->blob->decode(raw: json_encode($blob)));
		$this->assertSame(['intake' => 'active'], $this->blob->decode(raw: $blob)['planItemStates']);
	}//end testBothStoredShapesDecodeAlike()

	/**
	 * A blob that is there but unreadable is refused by name.
	 *
	 * @return void
	 */
	public function testAnUndecodableBlobIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('blob_undecodable');
		$this->blob->decode(raw: '{not json');
	}//end testAnUndecodableBlobIsRefused()

	/**
	 * Recorded states land on their nodes at every depth.
	 *
	 * @return void
	 */
	public function testStatesAreStampedOnNestedNodes(): void {
		$stamped = $this->blob->withStates(definition: self::definition(), states: ['intake' => 'completed', 'controle' => 'active']);

		$this->assertSame('completed', $stamped['items'][0]['state']);
		$this->assertSame('active', $stamped['items'][0]['children'][0]['state']);
		$this->assertArrayNotHasKey('state', $stamped['items'][1]);
	}//end testStatesAreStampedOnNestedNodes()

	/**
	 * An item id no definition declares makes the case unmappable.
	 *
	 * @return void
	 */
	public function testAnOrphanItemIsRefused(): void {
		$this->expectExceptionMessage('orphan_item:gone');
		$this->blob->withStates(definition: self::definition(), states: ['gone' => 'active']);
	}//end testAnOrphanItemIsRefused()

	/**
	 * A state outside the six makes the case unmappable.
	 *
	 * @return void
	 */
	public function testAStateOutsideTheSixIsRefused(): void {
		$this->expectExceptionMessage('unknown_state:intake');
		$this->blob->withStates(definition: self::definition(), states: ['intake' => 'suspended']);
	}//end testAStateOutsideTheSixIsRefused()

	/**
	 * The event log becomes history entries in OpenRegister's shape.
	 *
	 * @return void
	 */
	public function testTheEventLogBecomesHistory(): void {
		$history = $this->blob->history(
			eventLog: [
				['at' => '2026-09-01T10:00:00+02:00', 'itemId' => 'intake', 'itemType' => 'stage', 'from' => 'available', 'to' => 'active'],
				['at' => '2026-09-02T10:00:00Z', 'itemId' => 'controle', 'from' => '', 'to' => 'active'],
			],
			definition: self::definition()
		);

		$this->assertSame(['item' => 'intake', 'to' => 'active', 'at' => '2026-09-01T10:00:00+02:00', 'from' => 'available'], $history[0]);
		$this->assertArrayNotHasKey('from', $history[1]);
	}//end testTheEventLogBecomesHistory()

	/**
	 * A moment OpenRegister would refuse is caught here, naming the entry.
	 *
	 * @return void
	 */
	public function testAnUnreadableMomentIsRefused(): void {
		$this->expectExceptionMessage('unreadable_moment:0');
		$this->blob->history(eventLog: [['at' => 'yesterday', 'itemId' => 'intake', 'to' => 'active']], definition: self::definition());
	}//end testAnUnreadableMomentIsRefused()

	/**
	 * History naming an undeclared item is refused.
	 *
	 * @return void
	 */
	public function testHistoryNamingAnOrphanIsRefused(): void {
		$this->expectExceptionMessage('orphan_item:gone');
		$this->blob->history(eventLog: [['at' => '2026-09-01T10:00:00Z', 'itemId' => 'gone', 'to' => 'active']], definition: self::definition());
	}//end testHistoryNamingAnOrphanIsRefused()

	/**
	 * Verification names every recorded state the rows do not hold.
	 *
	 * @return void
	 */
	public function testMismatchesNameMissingAndDifferingRows(): void {
		$this->assertSame([], $this->blob->mismatches(states: ['intake' => 'active'], rows: [['key' => 'intake', 'state' => 'active']]));
		$this->assertSame(
			['intake: completed -> active', 'besluit: available -> missing'],
			$this->blob->mismatches(
				states: ['intake' => 'completed', 'besluit' => 'available'],
				rows: [['key' => 'intake', 'state' => 'active']]
			)
		);
	}//end testMismatchesNameMissingAndDifferingRows()

	/**
	 * A stage with one child task, and a milestone.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function definition(): array {
		return [
			'settings' => [],
			'items' => [
				['key' => 'intake', 'type' => 'stage', 'children' => [['key' => 'controle', 'type' => 'humanTask']]],
				['key' => 'besluit', 'type' => 'milestone'],
			],
		];
	}//end definition()
}//end class
