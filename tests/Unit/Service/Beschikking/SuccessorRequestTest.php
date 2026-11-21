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
			self::fail('A kind is compared exactly.');
		} catch (RefusedException $refusal) {
			self::assertSame('successor-kind-unknown', $refusal->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
		}

		self::assertSame(['amendment', 'withdrawal'], SuccessorRequest::KINDS);
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
			['decisionType' => 'withdrawal', 'supersedes' => 'b-1', 'addressee' => ['name' => 'M. Jansen']],
			$bare->fieldsFrom(original: $original)
		);

		$written = new SuccessorRequest(
			originalId: 'b-1',
			kind: 'amendment',
			overrides: ['addressee' => ['name' => 'P. de Vries'], 'rationale' => null, 'decision' => ['text' => 'Nieuw.'], 'other' => 'x'],
			templateId: null
		);
		self::assertSame(
			[
				'decisionType' => 'amendment',
				'supersedes' => 'b-1',
				'addressee' => ['name' => 'P. de Vries'],
				'rationale' => null,
				'decision' => ['text' => 'Nieuw.'],
			],
			$written->fieldsFrom(original: $original),
			'A written null is still written; a field the successor does not take is dropped.'
		);

		self::assertSame([], $bare->fieldsFrom(original: [])['addressee'], 'No addressee anywhere: an empty one.');
	}//end testTheFieldsCarryTheAddresseeAndOnlyWhatWasWritten()

	/**
	 * The case is the original's; the template is the named one, else the original's, else none.
	 *
	 * @return void
	 */
	public function testTheCaseAndTheTemplate(): void {
		$original = ['caseId' => 'case-1', 'templateId' => ' tpl-a '];

		$unnamed = new SuccessorRequest(originalId: 'b-1', kind: 'amendment', overrides: [], templateId: null);
		self::assertSame('case-1', $unnamed->caseIdFrom(original: $original));
		self::assertSame('', $unnamed->caseIdFrom(original: []));
		self::assertSame('tpl-a', $unnamed->templateFrom(original: $original));
		self::assertNull($unnamed->templateFrom(original: ['templateId' => '   ']));
		self::assertNull($unnamed->templateFrom(original: []));

		$named = new SuccessorRequest(originalId: 'b-1', kind: 'amendment', overrides: [], templateId: 'tpl-b');
		self::assertSame('tpl-b', $named->templateFrom(original: $original));
	}//end testTheCaseAndTheTemplate()
}//end class
