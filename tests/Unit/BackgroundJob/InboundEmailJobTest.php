<?php

/**
 * What the inbound poller reads out of a subject line.
 *
 * 🔴 THIS JOB HAD NO TEST, AND IT HAD A DEFECT THE WHOLE TIME. The pattern
 * captures the WHOLE tag, prefix included, and the job handed that string on as
 * if it were a case id. A case id is a uuid and the identifier a case carries is
 * the bare `YYYY-NNNN`, so the prefixed capture matched no case and could not
 * have. Every mail the job reported as linked was archived against a case that
 * does not exist, and the only symptom was an info line saying it had worked.
 *
 * These pin the two halves separately: the tag pattern (unchanged, and still
 * the app's public contract for what a case tag looks like) and the identifier
 * inside it (new, and the thing that is actually looked up).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\InboundEmailJob;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for InboundEmailJob's subject reading.
 *
 * @covers \OCA\Dossiq\BackgroundJob\InboundEmailJob
 */
class InboundEmailJobTest extends TestCase {

	/**
	 * The job under test.
	 *
	 * @var InboundEmailJob
	 */
	private InboundEmailJob $job;

	/**
	 * Set up a job whose collaborators are never reached.
	 *
	 * The two methods under test read a string and nothing else.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('300');

		$this->job = new InboundEmailJob(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$this->createMock(IAppManager::class),
			$this->createMock(SettingsService::class),
			$this->createMock(EmailArchivalService::class),
			$this->createMock(CaseEmailRepository::class),
			$this->createMock(UnmatchedMailIntake::class),
			new NullLogger()
		);
	}//end setUp()

	/**
	 * The tag pattern still matches what it always did.
	 *
	 * @return void
	 */
	public function testTheTagIsStillMatchedAsAWhole(): void {
		self::assertSame(
			'ZAAK-2026-000142',
			$this->job->matchCaseFromSubject(subject: 'Re: [ZAAK-2026-000142] uw aanvraag')
		);
	}//end testTheTagIsStillMatchedAsAWhole()

	/**
	 * 🔴 The identifier is the tag WITHOUT its prefix.
	 *
	 * Looking up the prefixed string against `identifier` is the defect: no
	 * case carries `ZAAK-` in that field.
	 *
	 * @return void
	 */
	public function testTheIdentifierDropsThePrefix(): void {
		self::assertSame(
			'2026-000142',
			$this->job->identifierFromSubject(subject: 'Re: [ZAAK-2026-000142] uw aanvraag')
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
			$this->job->identifierFromSubject(subject: '[VERGUNNING-2026-0042] vraag')
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
		self::assertNull($this->job->identifierFromSubject(subject: 'Vraag over mijn aanvraag'));
		self::assertNull($this->job->matchCaseFromSubject(subject: 'Vraag over mijn aanvraag'));
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
		self::assertNull($this->job->identifierFromSubject(subject: 'factuur ZAAK-2026-000142'));
	}//end testABareNumberIsNotATag()

	/**
	 * An empty subject names no case rather than throwing.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectNamesNoCase(): void {
		self::assertNull($this->job->identifierFromSubject(subject: ''));
	}//end testAnEmptySubjectNamesNoCase()
}//end class
