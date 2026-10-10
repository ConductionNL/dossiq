<?php

/**
 * File a case for hermiq's conversational intake, through the create form's own path.
 *
 * The hermiq intake ends a conversation by calling the tool an owning app marked
 * for intake, with four arguments: the request type it classified, the subject,
 * the person and the transcript. This class turns those into the same write the
 * create form makes: one `saveObject()` on the case schema, as the caller, with
 * no elevation. So every rule the create form meets on the server meets this
 * filing too: the case schema's own validation, the case type's
 * `intakeRequirements` (`IntakeRequirementsListener` refuses a case that may not
 * exist yet), the duplicate policy and OpenRegister RBAC. A refusal from any of
 * them is a refusal here, and hermiq turns it into a handover to a person.
 *
 * 🔴 THERE IS NO SECOND, LOOSER PATH. A conversation has not collected every
 * field the form asks for, and the temptation is to fill the gaps or to write
 * elevated. Both would be a second definition of a valid case. A missing field
 * is a refusal (design D-5), never a half-filed case.
 *
 * 🔴 NO INTERMEDIATE INTAKE OBJECT (decision 179). The conversation files the
 * case itself; there is no queue item in between.
 *
 * What the arguments may fill is an allowlist: a title, a description, the case
 * type, who asked and the channel. Nothing that decides who may read the case,
 * who holds it or where it is in its lifecycle comes from the conversation.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Files one case from an intake conversation, or refuses it in words.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */
class CaseIntakeFiling {

	/**
	 * The channel a conversational intake files under. hermiq's intake runs on
	 * the public web chat and the portal, both of which the case schema's
	 * `intakeChannel` enum calls `website`.
	 *
	 * @var string
	 */
	public const CHANNEL = 'website';

	/**
	 * The case schema's `title` limit.
	 *
	 * @var int
	 */
	private const TITLE_MAX = 255;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service, register and case schema.
	 * @param CaseTypeStore   $caseTypes       Reads a case type by id or by identifier.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File the case.
	 *
	 * @param string                           $type     The classified request type: a case type id or identifier.
	 * @param string                           $subject  What the request is about; becomes the case title.
	 * @param string                           $person   Who asked, as the conversation knows them.
	 * @param array<int, array<string, mixed>> $messages The transcript, oldest first.
	 *
	 * @return array<string, mixed> The saved case.
	 *
	 * @throws RefusedException When the case type is unknown or not published, the subject is empty,
	 *                          storage is unavailable, or the create path refuses the write.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-intake-tool-creates-through-the-existing-path
	 */
	public function file(string $type, string $subject, string $person, array $messages): array {
		$subject = trim($subject);
		if ($subject === '') {
			throw new RefusedException(
				rule: 'intake-subject-missing',
				sentence: 'dossiq files no case without a subject: hand the conversation to a person.',
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		$caseType = $this->publishedCaseType(type: $type);

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			throw RefusedException::indeterminate(
				rule: 'intake-storage-unavailable',
				sentence: 'dossiq cannot reach its case register, so it filed nothing.'
			);
		}

		$payload = $this->payload(caseTypeId: (string)($caseType['id'] ?? ''), subject: $subject, person: $person, messages: $messages);

		try {
			$saved = $objectService->saveObject(object: $payload, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info('dossiq: the intake filing was refused by the create path', ['exception' => $e->getMessage()]);

			throw new RefusedException(
				rule: 'intake-refused-by-create-path',
				sentence: 'dossiq refused to file this case: ' . $this->reasonOf(error: $e),
				status: RefusedException::STATUS_UNPROCESSABLE,
				previous: $e
			);
		}

		return $this->asArray(value: $saved);
	}//end file()

	/**
	 * The payload the create form would write, built from an allowlist.
	 *
	 * @param string                           $caseTypeId The resolved case type uuid.
	 * @param string                           $subject    The subject.
	 * @param string                           $person     Who asked.
	 * @param array<int, array<string, mixed>> $messages   The transcript.
	 *
	 * @return array<string, string> The case payload.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-intake-tool-creates-through-the-existing-path
	 */
	public function payload(string $caseTypeId, string $subject, string $person, array $messages): array {
		$payload = [
			'title' => mb_substr($subject, 0, self::TITLE_MAX),
			'caseType' => $caseTypeId,
			'intakeChannel' => self::CHANNEL,
		];

		$transcript = $this->transcript(messages: $messages);
		if ($transcript !== '') {
			$payload['description'] = $transcript;
		}

		$person = trim($person);
		if ($person !== '') {
			$payload['initiatorDisplayName'] = $person;
		}

		return $payload;
	}//end payload()

	/**
	 * The case type the classification names, when it is published.
	 *
	 * Accepts the case type's uuid or its identifier. A draft is refused: the
	 * create form's case type picker filters drafts out (`x-relation-filter`),
	 * so a case of a draft type is one the form could not have filed.
	 *
	 * @param string $type The classified request type.
	 *
	 * @return array<string, mixed> The case type.
	 *
	 * @throws RefusedException When no published case type answers to it.
	 */
	private function publishedCaseType(string $type): array {
		$type = trim($type);
		$candidates = [];
		if ($type !== '') {
			$byId = $this->caseTypes->readCaseType(caseTypeId: $type);
			if ($byId !== []) {
				$candidates[] = $byId;
			}

			if ($candidates === []) {
				$candidates = $this->caseTypes->versionsWithIdentifier(identifier: $type);
			}
		}

		foreach ($candidates as $candidate) {
			if (($candidate['isDraft'] ?? false) !== true && (string)($candidate['id'] ?? '') !== '') {
				return $candidate;
			}
		}

		throw new RefusedException(
			rule: 'intake-case-type-unknown',
			sentence: sprintf('dossiq has no published case type "%s", so it filed nothing.', $type),
			status: RefusedException::STATUS_UNPROCESSABLE
		);
	}//end publishedCaseType()

	/**
	 * The transcript as plain text, one message per line.
	 *
	 * @param array<int, array<string, mixed>> $messages The messages.
	 *
	 * @return string The transcript.
	 */
	private function transcript(array $messages): string {
		$lines = [];
		foreach ($messages as $message) {
			if (is_array($message) === false) {
				continue;
			}

			$text = trim((string)($message['text'] ?? ''));
			if ($text === '') {
				continue;
			}

			$channel = trim((string)($message['channel'] ?? ''));
			if ($channel !== '') {
				$text = '[' . $channel . '] ' . $text;
			}

			$lines[] = $text;
		}

		return implode("\n", $lines);
	}//end transcript()

	/**
	 * The refusal's own words: a dossiq sentence when it is one, else the message.
	 *
	 * @param Throwable $error The refusal.
	 *
	 * @return string The reason.
	 */
	private function reasonOf(Throwable $error): string {
		if ($error instanceof RefusedException) {
			return $error->getSentence();
		}

		return $error->getMessage();
	}//end reasonOf()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $value An entity or an array.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end asArray()
}//end class
