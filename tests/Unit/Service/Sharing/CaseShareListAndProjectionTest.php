<?php

/**
 * Case Share List and Access Link Projection Test
 *
 * Pins what the sharing panel lists for a case's link shares (id, kind,
 * title, meta, status, url and whether the caller may revoke it), and what an
 * access link body keeps of each row: no metadata keys other than `@self`,
 * and of `@self` only the publishable keys.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\AccessLinkProjection;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\CaseShareList;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaseShareList and AccessLinkProjection.
 */
#[CoversClass(CaseShareList::class)]
#[CoversClass(AccessLinkProjection::class)]
final class CaseShareListAndProjectionTest extends TestCase {
	/**
	 * The list over the given link shares, with an l10n that substitutes its parameters.
	 *
	 * @param array<int,array<string,mixed>> $shares The shares the case has.
	 *
	 * @return CaseShareList
	 */
	private function list(array $shares): CaseShareList {
		$linkShares = $this->createMock(CaseLinkShares::class);
		$linkShares->expects(self::once())->method('listForCase')->with('case-1')->willReturn($shares);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		return new CaseShareList(linkShares: $linkShares, l10n: $l10n);
	}//end list()

	/**
	 * A labelled live share by the caller is an active, revocable case link with its meta.
	 *
	 * @return void
	 */
	public function testLabelledLiveShareOfTheCaller(): void {
		$rows = $this->list(
			shares: [[
				'accessLinkId'  => '12',
				'state'         => 'live',
				'label'         => '  Voor de aannemer ',
				'accessLinkUrl' => 'https://example.nl/s/abc',
				'createdBy'     => 'alice',
				'expiresAt'     => '2026-12-31T23:59:59+01:00',
			]]
		)->rows(caseId: 'case-1', uid: 'alice');

		self::assertSame(
			expected: [[
				'id'        => '12',
				'kind'      => 'case',
				'title'     => 'Voor de aannemer',
				'meta'      => 'by alice · until 2026-12-31',
				'status'    => 'active',
				'url'       => 'https://example.nl/s/abc',
				'link'      => 'https://example.nl/s/abc',
				'revocable' => true,
			]],
			actual: $rows
		);
	}//end testLabelledLiveShareOfTheCaller()

	/**
	 * A share of documents is a file link titled by its document count, given as a list or JSON.
	 *
	 * @return void
	 */
	public function testDocumentSharesAreFileLinks(): void {
		$rows = $this->list(
			shares: [
				['accessLinkId' => 1, 'sharedDocuments' => ['d1']],
				['accessLinkId' => 2, 'sharedDocuments' => '["d1","d2","d3"]'],
				['accessLinkId' => 3, 'sharedDocuments' => 'not json'],
			]
		)->rows(caseId: 'case-1', uid: 'alice');

		self::assertSame(
			expected: [['file', 'Link to 1 document'], ['file', 'Link to 3 documents'], ['case', 'Link to the case']],
			actual: array_map(static fn (array $row): array => [$row['kind'], $row['title']], $rows)
		);
		self::assertSame(expected: ['', '', ''], actual: array_column($rows, 'meta'));
	}//end testDocumentSharesAreFileLinks()

	/**
	 * Each state maps to its status; an unknown state reads as active. Only an active or paused
	 * share created by the caller is revocable.
	 *
	 * @return void
	 */
	public function testStatusAndRevocability(): void {
		$rows = $this->list(
			shares: [
				['accessLinkId' => 1, 'state' => 'paused', 'createdBy' => 'alice'],
				['accessLinkId' => 2, 'state' => 'expired', 'createdBy' => 'alice'],
				['accessLinkId' => 3, 'state' => 'revoked', 'createdBy' => 'alice'],
				['accessLinkId' => 4, 'state' => 'weird', 'createdBy' => 'alice'],
				['accessLinkId' => 5, 'state' => 'live', 'createdBy' => 'bob'],
				['accessLinkId' => 6, 'state' => 'live'],
			]
		)->rows(caseId: 'case-1', uid: 'alice');

		self::assertSame(
			expected: [
				['paused', true],
				['expired', false],
				['revoked', false],
				['active', true],
				['active', false],
				['active', false],
			],
			actual: array_map(static fn (array $row): array => [$row['status'], $row['revocable']], $rows)
		);
	}//end testStatusAndRevocability()

	/**
	 * Shares without a positive access link id are left out.
	 *
	 * @return void
	 */
	public function testSharesWithoutALinkAreLeftOut(): void {
		$rows = $this->list(shares: [['state' => 'live'], ['accessLinkId' => 0], ['accessLinkId' => '-3'], ['accessLinkId' => 9]])->rows(caseId: 'case-1', uid: 'alice');

		self::assertSame(expected: ['9'], actual: array_column($rows, 'id'));
	}//end testSharesWithoutALinkAreLeftOut()

	/**
	 * The subject and every result row lose their metadata keys except a reduced @self.
	 *
	 * @return void
	 */
	public function testProjectionKeepsOnlyPublishableMetadata(): void {
		$row = [
			'title'   => 'Bouwvergunning',
			'@self'   => ['id' => 'u-1', 'name' => 'Bouwvergunning', 'owner' => 'alice', 'organisation' => 'org-1', 'register' => 'dossiq', 'files' => ['x']],
			'@rbac'   => ['read' => ['alice']],
			'@audit'  => ['x'],
			'secret'  => 'kept, it is not metadata',
		];

		$body = (new AccessLinkProjection())->strip(
			body: ['subject' => $row, 'results' => [$row, 'not a row', ['@self' => 'broken', 'title' => 'Doc']], 'total' => 2, '@meta' => 'top level stays']
		);

		$stripped = ['title' => 'Bouwvergunning', '@self' => ['id' => 'u-1', 'name' => 'Bouwvergunning', 'register' => 'dossiq'], 'secret' => 'kept, it is not metadata'];
		self::assertSame(
			expected: [
				'subject' => $stripped,
				'results' => [$stripped, ['@self' => [], 'title' => 'Doc']],
				'total'   => 2,
				'@meta'   => 'top level stays',
			],
			actual: $body
		);
	}//end testProjectionKeepsOnlyPublishableMetadata()

	/**
	 * Every publishable @self key is kept, in the allowed order; a row without @self gets none.
	 *
	 * @return void
	 */
	public function testProjectionKeepsEveryPublishableSelfKey(): void {
		$self = array_combine(AccessLinkProjection::PUBLISHABLE_SELF_KEYS, AccessLinkProjection::PUBLISHABLE_SELF_KEYS);

		$body = (new AccessLinkProjection())->strip(body: ['subject' => ['@self' => array_reverse($self, true) + ['owner' => 'x']], 'results' => [['title' => 'no self']]]);

		self::assertSame(expected: $self, actual: $body['subject']['@self']);
		self::assertSame(expected: [['title' => 'no self']], actual: $body['results']);
	}//end testProjectionKeepsEveryPublishableSelfKey()

	/**
	 * A body without a subject or results, or with non-array ones, is returned unchanged.
	 *
	 * @return void
	 */
	public function testProjectionLeavesOtherBodiesAlone(): void {
		$projection = new AccessLinkProjection();

		self::assertSame(expected: ['message' => 'expired'], actual: $projection->strip(body: ['message' => 'expired']));
		self::assertSame(expected: ['subject' => 'x', 'results' => 'y'], actual: $projection->strip(body: ['subject' => 'x', 'results' => 'y']));
	}//end testProjectionLeavesOtherBodiesAlone()
}//end class
