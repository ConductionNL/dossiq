<?php

/**
 * What happens to a mail the shared mailbox cannot place.
 *
 * The behaviour being pinned is a change of outcome, so the test that matters
 * most is the one for the outcome that did NOT change: an instance that names
 * no fallback case type must still write nothing. Auto-creating cases out of a
 * public mailbox is exactly what `email-case-matching` design decision D4
 * refused, and the only thing that makes it safe here is that it is opt-in.
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for UnmatchedMailIntake.
 *
 * @covers \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 *
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 */
class UnmatchedMailIntakeTest extends TestCase {

	/**
	 * Everything the last built intake wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An intake over a fake store.
	 *
	 * @param string               $fallbackCaseType The configured case type, or ''.
	 * @param array<string, mixed> $caseType         The case type row the store answers.
	 * @param boolean              $configured       Whether the register resolves.
	 * @param boolean              $saveFails        Whether the write throws.
	 *
	 * @return UnmatchedMailIntake The intake.
	 */
	private function intake(
		string $fallbackCaseType,
		array $caseType = [],
		bool $configured = true,
		bool $saveFails = false,
	): UnmatchedMailIntake {
		$this->saved = [];
		$saved = &$this->saved;

		$objectService = new class($saved, $caseType, $saveFails) {
			/**
			 * @param array<int, array<string, mixed>> $saved     The write log.
			 * @param array<string, mixed>             $caseType  The case type row.
			 * @param boolean                          $saveFails Whether writes throw.
			 */
			public function __construct(
				private array &$saved,
				private array $caseType,
				private bool $saveFails,
			) {
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string $register, string $schema): array {
				return $this->caseType;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if ($this->saveFails === true) {
					throw new RuntimeException('unwritable');
				}

				$this->saved[] = ['object' => $object, 'schema' => $schema];

				return ($object + ['id' => 'case-new']);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($configured): string {
				if ($configured === false) {
					return '';
				}

				return ($key === 'register' ? 'dossiq' : $key);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($fallbackCaseType);

		return new UnmatchedMailIntake(
			settingsService: $settings,
			assignees: new AssigneeResolver(new NullLogger()),
			appConfig: $appConfig,
			logger: new NullLogger()
		);
	}//end intake()

	/**
	 * The message a test files.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The message row.
	 */
	private function message(array $overrides = []): array {
		return array_merge(
			[
				'subject' => 'Vraag over mijn aanvraag',
				'from' => 'jan@example.org',
				'sentAt' => 'Mon, 8 Sep 2026 09:12:00 +0200',
			],
			$overrides
		);
	}//end message()

	/**
	 * 🔴 An instance that names no fallback type writes NOTHING.
	 *
	 * This is the guard on the whole feature. Turning a public mailbox into a
	 * case factory without being asked is worse than dropping the mail, and it
	 * is the reason the setting exists rather than a hardcoded default.
	 *
	 * @return void
	 */
	public function testNoFallbackTypeMeansNoCaseAndNoWrite(): void {
		$intake = $this->intake(fallbackCaseType: '');

		self::assertNull($intake->caseFor(message: $this->message()));
		self::assertSame([], $this->saved);
		self::assertFalse($intake->isConfigured());
	}//end testNoFallbackTypeMeansNoCaseAndNoWrite()

	/**
	 * A configured fallback type files the mail as a case.
	 *
	 * @return void
	 */
	public function testAConfiguredFallbackTypeFilesTheMail(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1');

		self::assertSame('case-new', $intake->caseFor(message: $this->message()));

		$written = $this->saved[0]['object'];
		self::assertSame('Vraag over mijn aanvraag', $written['title']);
		self::assertSame('ct-1', $written['caseType']);
		self::assertSame('email', $written['intakeChannel']);
		self::assertStringContainsString('jan@example.org', $written['description']);
	}//end testAConfiguredFallbackTypeFilesTheMail()

	/**
	 * A mail with no subject still becomes a case with a title.
	 *
	 * The case schema requires one, and an empty title makes a row nobody can
	 * find in a list.
	 *
	 * @return void
	 */
	public function testAMailWithNoSubjectStillGetsATitle(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1');
		$intake->caseFor(message: $this->message(['subject' => '   ']));

		self::assertSame('Bericht zonder onderwerp', $this->saved[0]['object']['title']);
	}//end testAMailWithNoSubjectStillGetsATitle()

	/**
	 * A subject longer than the schema allows is cut, not refused.
	 *
	 * @return void
	 */
	public function testALongSubjectIsCutToTheSchemasLimit(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1');
		$intake->caseFor(message: $this->message(['subject' => str_repeat('a', 400)]));

		self::assertSame(255, mb_strlen($this->saved[0]['object']['title']));
	}//end testALongSubjectIsCutToTheSchemasLimit()

	/**
	 * 🔴 The case type's default assignee reaches the case.
	 *
	 * The schema's prefill maps `defaultAssignee` onto `assignee` at FORM time,
	 * and there is no form here. A background job that left it to the prefill
	 * would file every mail onto a case with nobody on it, which is the same
	 * hole this change closes for tasks.
	 *
	 * @return void
	 */
	public function testTheCaseTypesDefaultAssigneeReachesTheCase(): void {
		$intake = $this->intake(
			fallbackCaseType: 'ct-1',
			caseType: ['id' => 'ct-1', 'defaultAssignee' => 'behandelaars']
		);
		$intake->caseFor(message: $this->message());

		self::assertSame('behandelaars', $this->saved[0]['object']['assignee']);
	}//end testTheCaseTypesDefaultAssigneeReachesTheCase()

	/**
	 * A case type naming nobody files the case with nobody, not with a template.
	 *
	 * @return void
	 */
	public function testACaseTypeNamingNobodyFilesAnUnassignedCase(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1', caseType: ['id' => 'ct-1']);
		$intake->caseFor(message: $this->message());

		self::assertSame('', $this->saved[0]['object']['assignee']);
	}//end testACaseTypeNamingNobodyFilesAnUnassignedCase()

	/**
	 * An unconfigured register writes nothing, rather than half a case.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterWritesNothing(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1', configured: false);

		self::assertNull($intake->caseFor(message: $this->message()));
		self::assertSame([], $this->saved);
	}//end testAnUnconfiguredRegisterWritesNothing()

	/**
	 * A failed write answers null rather than a case id nothing stands behind.
	 *
	 * The caller archives the mail onto whatever id comes back, so inventing
	 * one would attach the mail to nothing.
	 *
	 * @return void
	 */
	public function testAFailedWriteAnswersNull(): void {
		$intake = $this->intake(fallbackCaseType: 'ct-1', saveFails: true);

		self::assertNull($intake->caseFor(message: $this->message()));
	}//end testAFailedWriteAnswersNull()
}//end class
