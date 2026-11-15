<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Every case act refuses a case that cannot be found, the same way, and writes nothing.
 */
class CaseActsUnknownCaseTest extends TestCase {

	/**
	 * A store that finds no case and must not be written.
	 *
	 * @return CaseStatusStore The store.
	 */
	private function store(): CaseStatusStore {
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn(null);
		$store->expects(self::never())->method('saveCase');

		return $store;
	}//end store()

	/**
	 * The refusal a call throws, as rule, status and sentence.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: string, 1: int, 2: string} The refusal.
	 */
	private static function refusal(callable $call): array {
		try {
			$call();
		} catch (RefusedException $e) {
			return [$e->getRule(), $e->getStatus(), $e->getSentence()];
		}

		self::fail('Not refused');
	}//end refusal()

	/**
	 * Hold, release, record, begin and promote all refuse an unknown case.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseIsRefused(): void {
		$journal = new CaseJournal(userSession: $this->createMock(IUserSession::class));
		$holds = new CaseHoldActs(store: $this->store(), journal: $journal, dates: $this->createMock(CaseDateNormaliser::class));
		$incompleteness = new CaseIncompleteness(store: $this->store(), journal: $journal);
		$drafts = new DraftCaseActs(store: $this->store(), journal: $journal);
		$expected = ['case-not-found', RefusedException::STATUS_UNPROCESSABLE, 'This case could not be found.'];

		self::assertSame($expected, self::refusal(fn () => $holds->hold(caseId: 'gone', reason: '', until: '')));
		self::assertSame($expected, self::refusal(fn () => $holds->release(caseId: 'gone', reason: '')));
		self::assertSame($expected, self::refusal(fn () => $incompleteness->record(caseId: 'gone', missing: [])));
		self::assertSame($expected, self::refusal(fn () => $drafts->begin(caseId: 'gone')));
		self::assertSame($expected, self::refusal(fn () => $drafts->promote(caseId: 'gone')));
	}//end testAnUnknownCaseIsRefused()
}//end class
