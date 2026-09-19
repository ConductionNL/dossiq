<?php

/**
 * What intake reads out of a subject line.
 *
 * 🔴 THE JOB HAD NO TEST AND IT HAD A DEFECT THE WHOLE TIME. The pattern
 * captures the WHOLE tag, prefix included, and the job handed that string on as
 * if it were a case id. A case id is a uuid and the identifier a case carries is
 * the bare `YYYY-NNNN`, so the prefixed capture matched no case and could not
 * have. Every mail the job reported as linked was archived against a case that
 * does not exist, and the only symptom was an info line saying it had worked.
 *
 * These cases moved here with the reading itself: `InboundEmailJob` no longer
 * parses a subject, because it no longer decides anything.
 * {@see \OCA\Dossiq\Service\Email\InboundMailIntake} owns the whole intake path
 * now, and the tag reading went with it.
 *
 * ONE CONTRACT CHANGED and it is stated rather than discovered: a subject with
 * no tag answers `''` where it used to answer `null`. The caller is the same
 * class, and an empty identifier is never looked up.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationVerdict;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Email\ThreadingCheck;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Tests\Support\FakeMailGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the subject-tag reading.
 *
 * @covers \OCA\Dossiq\Service\Email\InboundMailIntake
 * @uses \OCA\Dossiq\Service\Email\AuthenticationVerdict
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterPipeline
 * @uses \OCA\Dossiq\Service\Email\ThreadingCheck
 */
class SubjectTagTest extends TestCase {

	/**
	 * The intake under test.
	 *
	 * @var InboundMailIntake
	 */
	private InboundMailIntake $intake;

	/**
	 * Set up an intake whose collaborators are never reached.
	 *
	 * The method under test reads a string and nothing else.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$gateway = new FakeMailGateway();

		$this->intake = new InboundMailIntake(
			gateway: $gateway,
			pipeline: new FilterPipeline(logger: new NullLogger(), filters: []),
			verdicts: new AuthenticationVerdict(
				gateway: $gateway,
				threading: new ThreadingCheck(gateway: $gateway)
			),
			threading: new ThreadingCheck(gateway: $gateway),
			policy: $this->createMock(IntakePolicy::class),
			log: $this->createMock(IntakeLog::class),
			cases: $this->createMock(CaseEmailRepository::class),
			unmatched: $this->createMock(UnmatchedMailIntake::class),
			archival: $this->createMock(EmailArchivalService::class),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * The identifier is the tag WITHOUT its prefix.
	 *
	 * Looking up the prefixed string against `identifier` is the defect: no
	 * case carries `ZAAK-` in that field.
	 *
	 * @return void
	 */
	public function testTheIdentifierDropsThePrefix(): void {
		self::assertSame(
			'2026-000142',
			$this->intake->identifierFromSubject(subject: 'Re: [ZAAK-2026-000142] uw aanvraag')
		);
	}//end testTheIdentifierDropsThePrefix()

	/**
	 * Any prefix a deployment uses is dropped, not just ZAAK.
	 *
	 * @return void
	 */
	public function testAnyPrefixIsDropped(): void {
		self::assertSame(
			'2026-0042',
			$this->intake->identifierFromSubject(subject: '[VERGUNNING-2026-0042] vraag')
		);
	}//end testAnyPrefixIsDropped()

	/**
	 * A subject with no tag names no case.
	 *
	 * This is the case that used to be dropped in silence.
	 *
	 * @return void
	 */
	public function testASubjectWithNoTagNamesNoCase(): void {
		self::assertSame('', $this->intake->identifierFromSubject(subject: 'Vraag over mijn aanvraag'));
	}//end testASubjectWithNoTagNamesNoCase()

	/**
	 * A number without the brackets is not a tag.
	 *
	 * The subject-tag contract is what the app puts in its own outbound mail;
	 * loosening it here would make any four-digit year in a subject look like a
	 * case reference.
	 *
	 * @return void
	 */
	public function testABareNumberIsNotATag(): void {
		self::assertSame('', $this->intake->identifierFromSubject(subject: 'factuur ZAAK-2026-000142'));
	}//end testABareNumberIsNotATag()

	/**
	 * An empty subject names no case rather than throwing.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectNamesNoCase(): void {
		self::assertSame('', $this->intake->identifierFromSubject(subject: ''));
	}//end testAnEmptySubjectNamesNoCase()
}//end class
