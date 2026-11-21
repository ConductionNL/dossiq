<?php

/**
 * Unit tests for SuccessorRequest.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\SuccessorRequest;
use PHPUnit\Framework\TestCase;

/**
 * What a successor request says without a store: its kind, its fields, its case and its template.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\SuccessorRequest
 * @uses   \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SuccessorRequestTest extends TestCase {

	/**
	 * Only an amendment or a withdrawal is a kind a successor may be; anything else is a 422.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoKindsAreKnown(): void {
		foreach (SuccessorRequest::KINDS as $kind) {
			(new SuccessorRequest(originalId: 'b-1', kind: $kind, overrides: [], templateId: null))->assertKnownKind();
		}

		try {
			(new SuccessorRequest(originalId: 'b-1', kind: 'Amendment', overrides: [], templateId: null))->assertKnownKind();
			self::fail(message: 'A kind is compared exactly.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: 'successor-kind-unknown', actual: $refusal->getRule());
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $refusal->getStatus());
		}

		self::assertSame(expected: ['amendment', 'withdrawal'], actual: SuccessorRequest::KINDS);
	}//end testOnlyTheTwoKindsAreKnown()

	/**
	 * The addressee carries over unless one is written; rationale and decision only when written.
	 *
	 * @return void
	 */
	public function testTheFieldsCarryTheAddresseeAndOnlyWhatWasWritten(): void {
		$original = ['caseId' => 'case-1', 'addressee' => ['name' => 'M. Jansen'], 'rationale' => 'Oud.'];

		$bare = new SuccessorRequest(originalId: 'b-1', kind: 'withdrawal', overrides: [], templateId: null);
		self::assertSame(
			expected: ['decisionType' => 'withdrawal', 'supersedes' => 'b-1', 'addressee' => ['name' => 'M. Jansen']],
			actual: $bare->fieldsFrom(original: $original)
		);

		$written = new SuccessorRequest(
			originalId: 'b-1',
			kind: 'amendment',
			overrides: ['addressee' => ['name' => 'P. de Vries'], 'rationale' => null, 'decision' => ['text' => 'Nieuw.'], 'other' => 'x'],
			templateId: null
		);
		self::assertSame(
			expected: [
				'decisionType' => 'amendment',
				'supersedes' => 'b-1',
				'addressee' => ['name' => 'P. de Vries'],
				'rationale' => null,
				'decision' => ['text' => 'Nieuw.'],
			],
			actual: $written->fieldsFrom(original: $original),
			message: 'A written null is still written; a field the successor does not take is dropped.'
		);

		self::assertSame(expected: [], actual: $bare->fieldsFrom(original: [])['addressee'], message: 'No addressee anywhere: an empty one.');
	}//end testTheFieldsCarryTheAddresseeAndOnlyWhatWasWritten()

	/**
	 * The case is the original's; the template is the named one, else the original's, else none.
	 *
	 * @return void
	 */
	public function testTheCaseAndTheTemplate(): void {
		$original = ['caseId' => 'case-1', 'templateId' => ' tpl-a '];

		$unnamed = new SuccessorRequest(originalId: 'b-1', kind: 'amendment', overrides: [], templateId: null);
		self::assertSame(expected: 'case-1', actual: $unnamed->caseIdFrom(original: $original));
		self::assertSame(expected: '', actual: $unnamed->caseIdFrom(original: []));
		self::assertSame(expected: 'tpl-a', actual: $unnamed->templateFrom(original: $original));
		self::assertNull(actual: $unnamed->templateFrom(original: ['templateId' => '   ']));
		self::assertNull(actual: $unnamed->templateFrom(original: []));

		$named = new SuccessorRequest(originalId: 'b-1', kind: 'amendment', overrides: [], templateId: 'tpl-b');
		self::assertSame(expected: 'tpl-b', actual: $named->templateFrom(original: $original));
	}//end testTheCaseAndTheTemplate()
}//end class
