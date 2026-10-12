<?php

/**
 * Unit tests for StufZdsCaseConcludedListener.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\StufZdsCaseConcludedListener;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Stuf\NeedsInputDispatcher;
use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\StufZdsCaseConcludedListener
 */
class StufZdsCaseConcludedListenerTest extends TestCase {

	/**
	 * Each failure code becomes the Notifier subject it always was.
	 *
	 * @return void
	 */
	public function testAFailureNotifiesWithItsSubject(): void {
		$cases = ['CIRCUIT_OPEN' => Notifier::SUBJECT_STUF_CIRCUIT_OPEN, 'TIMEOUT' => Notifier::SUBJECT_STUF_TIMEOUT, 'StUF064' => Notifier::SUBJECT_STUF_PERMANENT_ERROR];
		foreach ($cases as $code => $subject) {
			$needsInput = $this->createMock(NeedsInputDispatcher::class);
			$needsInput->expects($this->once())->method('dispatch')->with(
				$subject,
				$this->callback(static fn (array $context): bool => $context['endpointId'] === 'ep-1' && $context['fout'] === $code && $context['referentienummer'] === 'R1')
			);

			(new StufZdsCaseConcludedListener($needsInput))->handle(self::event(app: 'dossiq', success: false, retrying: false, code: $code));
		}
	}//end testAFailureNotifiesWithItsSubject()

	/**
	 * A success, a pending retry, another app's message or another event notifies nobody.
	 *
	 * @return void
	 */
	public function testNothingElseNotifies(): void {
		$needsInput = $this->createMock(NeedsInputDispatcher::class);
		$needsInput->expects($this->never())->method('dispatch');
		$listener = new StufZdsCaseConcludedListener($needsInput);

		$listener->handle(self::event(app: 'dossiq', success: true, retrying: false, code: ''));
		$listener->handle(self::event(app: 'dossiq', success: false, retrying: true, code: 'TIMEOUT'));
		$listener->handle(self::event(app: 'pipelinq', success: false, retrying: false, code: 'StUF064'));
		$listener->handle(new Event());
	}//end testNothingElseNotifies()

	/**
	 * A conclusion event.
	 *
	 * @param string $app      The asking app.
	 * @param bool   $success  Whether it succeeded.
	 * @param bool   $retrying Whether a retry is pending.
	 * @param string $code     The fout code.
	 *
	 * @return StufZdsCaseConcludedEvent The event.
	 */
	private static function event(string $app, bool $success, bool $retrying, string $code): StufZdsCaseConcludedEvent {
		$fout = null;
		if ($code !== '') {
			$fout = ['code' => $code, 'omschrijving' => 'x', 'details' => '', 'kind' => 'permanent'];
		}

		return new StufZdsCaseConcludedEvent(
			$app,
			'ep-1:abc',
			'vrijBericht',
			1,
			['success' => $success, 'retrying' => $retrying, 'referentienummer' => 'R1', 'messageId' => 'm1', 'caseIdentification' => null, 'fout' => $fout]
		);
	}//end event()
}//end class
