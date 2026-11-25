<?php

/**
 * AdviceTransitionWriter tests: a plain patch is written as an internal save, a terminal one after the chair signs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\AdviceTransitionWriter;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\InternalWriteMarker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The write of an advice request's transition.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\AdviceTransitionWriter
 * @uses   \OCA\Dossiq\Service\InternalWriteMarker
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AdviceTransitionWriterTest extends TestCase {

	/**
	 * @var BezwaarAuditTrail&MockObject
	 */
	private BezwaarAuditTrail&MockObject $auditTrail;

	/**
	 * Set up the trail.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditTrail = $this->createMock(BezwaarAuditTrail::class);
	}//end setUp()

	/**
	 * An object service whose patch records whether it ran as an internal write.
	 *
	 * @param InternalWriteMarker       $marker The marker the writer uses.
	 * @param array<string, mixed>|null $answer What the patch answers.
	 *
	 * @return object
	 */
	private function store(InternalWriteMarker $marker, ?array $answer): object {
		return new class($marker, $answer) {
			/**
			 * Each patch, and whether it ran marked as internal.
			 *
			 * @var array<int, array{0: mixed, 1: bool}>
			 */
			public array $patches = [];

			/**
			 * Constructor.
			 *
			 * @param InternalWriteMarker       $marker The marker.
			 * @param array<string, mixed>|null $answer The answer.
			 */
			public function __construct(private InternalWriteMarker $marker, private ?array $answer) {
			}

			/**
			 * Record the patch.
			 *
			 * @param mixed $patch The patch.
			 *
			 * @return array<string, mixed>|null
			 */
			public function patchObject(mixed $patch): ?array {
				$this->patches[] = [$patch, $this->marker->isInternal('req-1')];
				return $this->answer;
			}
		};
	}//end store()

	/**
	 * A non-terminal patch is saved as an internal write, with no signature.
	 *
	 * @return void
	 */
	public function testAPlainPatchIsAnInternalWrite(): void {
		$marker = new InternalWriteMarker();
		$store  = $this->store(marker: $marker, answer: ['id' => 'req-1', 'status' => 'in-deliberation']);
		$this->auditTrail->expects($this->never())->method('recordThenApply');

		$saved = (new AdviceTransitionWriter(auditTrail: $this->auditTrail, internalWrites: $marker))->apply(
			request: ['objectService' => $store, 'register' => 'reg', 'schema' => 'adviceRequest', 'id' => 'req-1', 'current' => []],
			update: ['status' => 'in-deliberation'],
		);

		$this->assertSame(['id' => 'req-1', 'status' => 'in-deliberation'], $saved);
		$this->assertCount(1, $store->patches);
		$this->assertTrue($store->patches[0][1]);
		$this->assertFalse($marker->isInternal('req-1'));
	}//end testAPlainPatchIsAnInternalWrite()

	/**
	 * A store that answers nothing gives the stored request with the patch over it.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerGivesTheMergedRequest(): void {
		$marker = new InternalWriteMarker();

		$saved = (new AdviceTransitionWriter(auditTrail: $this->auditTrail, internalWrites: $marker))->apply(
			request: [
				'objectService' => $this->store(marker: $marker, answer: null),
				'register' => 'reg',
				'schema' => 'adviceRequest',
				'id' => 'req-1',
				'current' => ['status' => 'assigned', 'bezwaar' => 'bz-1'],
			],
			update: ['status' => 'in-deliberation'],
		);

		$this->assertSame(['status' => 'in-deliberation', 'bezwaar' => 'bz-1'], $saved);
	}//end testAnEmptyAnswerGivesTheMergedRequest()

	/**
	 * A terminal patch records the chair's signature, then writes through the trail.
	 *
	 * @return void
	 */
	public function testATerminalPatchIsSignedFirst(): void {
		$marker = new InternalWriteMarker();
		$store  = $this->store(marker: $marker, answer: ['id' => 'req-1', 'status' => 'advice-issued']);
		$this->auditTrail->method('resolveActor')->willReturn('chair-1');
		$this->auditTrail->expects($this->once())->method('recordThenApply')->with(
			'reg',
			'adviceRequest',
			'req-1',
			'advice-signed-by-chair',
			['chair' => 'chair-1', 'signatureEvidence' => 'sig', 'conclusion' => 'Gegrond'],
			'',
			$this->isType('callable'),
		)->willReturnCallback(static fn (mixed ...$args): mixed => $args[6]());

		$saved = (new AdviceTransitionWriter(auditTrail: $this->auditTrail, internalWrites: $marker))->apply(
			request: ['objectService' => $store, 'register' => 'reg', 'schema' => 'adviceRequest', 'id' => 'req-1', 'current' => ['conclusion' => 'Gegrond']],
			update: ['status' => 'advice-issued', 'adviceIssuedAt' => '2026-11-25T10:00:00+00:00', 'signatureEvidence' => 'sig'],
		);

		$this->assertSame(['id' => 'req-1', 'status' => 'advice-issued'], $saved);
		$this->assertTrue($store->patches[0][1]);
	}//end testATerminalPatchIsSignedFirst()
}//end class
