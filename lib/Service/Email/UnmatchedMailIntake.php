<?php

/**
 * What happens to a mail the shared mailbox cannot place.
 *
 * 🔴 UNTIL NOW, NOTHING. The inbound poller matched a mail to a case by a tag
 * in its subject and, when that failed, did `continue`. No case, no task, no
 * log line, not even at debug. A citizen who replies without the tag, or writes
 * in for the first time, disappears into an inbox nobody is measured on. Every
 * competitor turns that mail into work; dossiq turned it into silence.
 *
 * The fix is an OPT-IN, and that matters. `email-case-matching` design decision
 * D4 recorded "strict no auto-create", and it was right about the thing it was
 * protecting against: a mailbox that silently manufactures cases out of spam is
 * worse than one that drops them. So creation happens only when an
 * administrator names a fallback case type, and the setting is empty by
 * default. An instance that names none behaves exactly as it does today.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a mail nothing matched into a case of the configured fallback type.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class UnmatchedMailIntake {

	/**
	 * The app-config key naming the case type an unmatched mail becomes.
	 */
	public const FALLBACK_CASE_TYPE_KEY = 'email_fallback_case_type';

	/**
	 * The title a mail with no subject gets.
	 */
	private const UNTITLED = 'Bericht zonder onderwerp';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param AssigneeResolver $assignees       The app's one answer to who work goes to.
	 * @param IAppConfig       $appConfig       Instance configuration.
	 * @param LoggerInterface  $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AssigneeResolver $assignees,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance turns unmatched mail into cases.
	 *
	 * @return boolean True when a fallback case type is configured.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function isConfigured(): bool {
		return ($this->fallbackCaseTypeId() !== '');
	}//end isConfigured()

	/**
	 * The case type an unmatched mail becomes a case of.
	 *
	 * @return string The case type id, or '' when the instance names none.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function fallbackCaseTypeId(): string {
		return trim(
			$this->appConfig->getValueString(Application::APP_ID, self::FALLBACK_CASE_TYPE_KEY, '')
		);
	}//end fallbackCaseTypeId()

	/**
	 * File an unmatched mail as a new case.
	 *
	 * @param array<string, mixed> $message The normalised message row.
	 *
	 * @return string|null The new case's id, or `null` when nothing was created.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function caseFor(array $message): ?string {
		$caseTypeId = $this->fallbackCaseTypeId();
		if ($caseTypeId === '') {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			$this->logger->warning(
				'UnmatchedMailIntake: a fallback case type is configured but the register is not, '
					. 'so the mail stays in the mailbox',
				['caseType' => $caseTypeId]
			);
			return null;
		}

		$payload = [
			'title' => $this->titleFor(message: $message),
			'description' => $this->descriptionFor(message: $message),
			'caseType' => $caseTypeId,
			// The channel is not a guess: this poller reads one mailbox.
			'intakeChannel' => 'email',
			'assignee' => $this->assigneeFor(caseTypeId: $caseTypeId),
		];

		try {
			$created = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $caseSchema,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'UnmatchedMailIntake: could not file the mail as a case',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return null;
		}

		$caseId = $this->assignees->caseId(case: $this->normaliseObjectRow(row: $created));
		if ($caseId === '') {
			$this->logger->error(
				'UnmatchedMailIntake: the case was written but answered no id, so the mail cannot be linked to it',
				['caseType' => $caseTypeId]
			);
			return null;
		}

		$this->logger->info(
			'UnmatchedMailIntake: filed an unmatched mail as case {case}',
			['case' => $caseId, 'caseType' => $caseTypeId]
		);

		return $caseId;
	}//end caseFor()

	/**
	 * The title the case takes from the mail.
	 *
	 * @param array<string, mixed> $message The message row.
	 *
	 * @return string The title, within the schema's 255.
	 */
	private function titleFor(array $message): string {
		$subject = trim((string)($message['subject'] ?? ''));
		if ($subject === '') {
			return self::UNTITLED;
		}

		return mb_substr($subject, 0, 255);
	}//end titleFor()

	/**
	 * What the case says about where it came from.
	 *
	 * The sender and the date, because the first question a handler asks of a
	 * case that appeared on its own is who sent it.
	 *
	 * @param array<string, mixed> $message The message row.
	 *
	 * @return string The description.
	 */
	private function descriptionFor(array $message): string {
		$from = trim((string)($message['from'] ?? ''));
		$sentAt = trim((string)($message['sentAt'] ?? ''));

		$lines = ['Deze zaak is aangemaakt uit een e-mail aan de functionele mailbox.'];
		if ($from !== '') {
			$lines[] = 'Afzender: ' . $from;
		}

		if ($sentAt !== '') {
			$lines[] = 'Verzonden: ' . $sentAt;
		}

		return implode("\n", $lines);
	}//end descriptionFor()

	/**
	 * Who the new case goes to.
	 *
	 * The case type's `defaultAssignee`, resolved through the same rule every
	 * other assignment uses. It is read here rather than left to the store
	 * because the schema's prefill maps `defaultAssignee` onto `assignee` at
	 * FORM time, and no form is involved: a case filed by a background job that
	 * left it to the prefill would arrive with nobody on it.
	 *
	 * @param string $caseTypeId The fallback case type.
	 *
	 * @return string The principal, or '' when the type names none.
	 */
	private function assigneeFor(string $caseTypeId): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return '';
		}

		try {
			$caseType = $objectService->find($caseTypeId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug(
				'UnmatchedMailIntake: could not read the fallback case type',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return '';
		}

		$row = $this->normaliseObjectRow(row: $caseType);

		return $this->assignees->resolve(
			primary: $this->assignees->referenceId(value: ($row['defaultAssignee'] ?? '')),
			fallback: '',
			case: []
		);
	}//end assigneeFor()

	/**
	 * Normalise one store answer into a plain row.
	 *
	 * @param mixed $row The answer.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function normaliseObjectRow(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end normaliseObjectRow()
}//end class
