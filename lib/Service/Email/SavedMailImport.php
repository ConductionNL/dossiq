<?php

/**
 * A saved `.eml` or `.msg` on a case, read as a message rather than stored
 * whole.
 *
 * Drop an Outlook `.msg` on a case today and you get a file nobody can read
 * without Outlook. integriq#2052 ships the reader: `MessageParser` takes the
 * bytes and the name, and the BYTES decide which reader runs rather than the
 * extension, so a `.msg` renamed `.eml` still parses as what it is.
 *
 * 🔴 DOSSIQ KEEPS NO SECOND PARSER. Reading MS-CFB in two apps is two answers
 * to one question, and the one in integriq is tested. This class hands the
 * bytes over and files what comes back; it never looks inside them.
 *
 * 🔴 IT CALLS INTEGRIQ IN PROCESS, NOT OVER HTTP. integriq's
 * `POST /api/mail-intake/import` is the same parser behind a session and a
 * multipart upload, and reaching it from PHP would mean forwarding the
 * caller's session to our own server. `FleetAppId::getService()` resolves the
 * parser itself, under whichever namespace this instance's integriq has, and
 * the import endpoint stays what a browser uses.
 *
 * 🔴 A MISSING APP IS NOT A PARSE THAT SUCCEEDED. Without integriq the file
 * is KEPT, untouched, and the reason is returned in words. An empty message
 * filed beside an unreadable attachment would be worse than the attachment
 * alone, because the case would then claim somebody had read it.
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Kernel\Contracts\SavedMailImportInterface;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a saved mail file to integriq's reader and files the message.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class SavedMailImport implements SavedMailImportInterface {

	/**
	 * integriq's parser, relative to integriq's own namespace.
	 *
	 * Resolved through FleetAppId rather than written as an FQN: integriq's id
	 * and namespace are both moving, and a literal against a dead one answers
	 * false without erroring, which would make this a silent no-op instead of
	 * the refusal it should be.
	 */
	private const PARSER = 'Service\\Mail\\MessageParser';

	/**
	 * The message was read and filed on the case.
	 */
	public const OUTCOME_IMPORTED = 'imported';

	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository $cases      Files the parsed message on the case.
	 * @param IAppManager         $appManager Answers whether integriq is present.
	 * @param ContainerInterface  $container  Resolves integriq's parser.
	 * @param LoggerInterface     $logger     Logger.
	 */
	public function __construct(
		private readonly CaseEmailRepository $cases,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one saved mail file as a message on a case.
	 *
	 * @param string $caseId   The case the file is on.
	 * @param string $fileName The file's name, for the parser's hint and the reason.
	 * @param string $raw      The file's bytes.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string}
	 *         What happened, and what was read.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function import(string $caseId, string $fileName, string $raw): array {
		$reason = $this->unreadableInput(caseId: $caseId, raw: $raw);
		if ($reason !== '') {
			return $this->kept(reason: $reason);
		}

		$parser = $this->mailReader();
		if ($parser === null) {
			return $this->kept(
				reason: 'Integriq is installed but carries no mail reader, so this version cannot '
					. 'read a saved mail file. The file is unchanged on this case.'
			);
		}

		$file = new SavedMailFile(caseId: $caseId, fileName: $fileName);
		try {
			$parsed = $parser->parse($fileName, $raw);
		} catch (Throwable $e) {
			return $this->unreadableFile(file: $file, failure: $e);
		}

		return $this->fileOnCase(file: $file, parsed: $parsed);
	}//end import()

	/**
	 * Why the input cannot be read at all, or '' when it can: no case, no
	 * bytes, or no integriq to read them.
	 *
	 * @param string $caseId The case the file is on.
	 * @param string $raw    The file's bytes.
	 *
	 * @return string The sentence a handler reads, or ''.
	 */
	private function unreadableInput(string $caseId, string $raw): string {
		if (trim($caseId) === '') {
			return 'This file is not on a case, so there is nothing to file a message on.';
		}

		if ($raw === '') {
			return 'The file is empty, so there is no message in it to read.';
		}

		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'integriq') === false) {
			return 'Integriq is not installed, and it is what reads a saved mail file. '
				. 'The file is unchanged on this case.';
		}

		return '';
	}//end unreadableInput()

	/**
	 * Integriq's mail reader, or null when this version carries none.
	 *
	 * @return object|null The parser.
	 */
	private function mailReader(): ?object {
		$parser = FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: self::PARSER
		);
		if ($parser === null || method_exists($parser, 'parse') === false) {
			return null;
		}

		return $parser;
	}//end mailReader()

	/**
	 * A file the reader could not read: logged, and kept.
	 *
	 * @param SavedMailFile $file    The file.
	 * @param Throwable     $failure What the reader said.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string} The outcome.
	 */
	private function unreadableFile(SavedMailFile $file, Throwable $failure): array {
		$this->logger->warning(
			'Dossiq: a saved mail file could not be read',
			['case' => $file->caseId, 'file' => $file->fileName, 'error' => $failure->getMessage()]
		);

		return $this->kept(
			reason: 'This file could not be read as a message. It is unchanged on this case.'
		);
	}//end unreadableFile()

	/**
	 * File the parsed message on the case.
	 *
	 * 🔴 THE ORIGINAL STAYS. It is the record: a parsed message is a reading
	 * of a file, and an archive that kept only the reading cannot answer a
	 * question about the bytes later on. Nothing here deletes or moves the node.
	 *
	 * @param SavedMailFile $file   The file.
	 * @param object        $parsed The parsed message.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string} The outcome.
	 */
	private function fileOnCase(SavedMailFile $file, object $parsed): array {
		$title = $this->titleOf(file: $file, parsed: $parsed);
		$from = $this->stringFrom(parsed: $parsed, method: 'getFrom');

		$this->cases->recordReceivedEmail(
			caseId: $file->caseId,
			from: $from,
			recipient: $this->firstRecipient(parsed: $parsed),
			subject: $title,
			body: $this->stringFrom(parsed: $parsed, method: 'getBodyText'),
			inReplyTo: ''
		);

		return [
			'outcome' => self::OUTCOME_IMPORTED,
			'reason' => '',
			'subject' => $title,
			'from' => $from,
			'receivedAt' => $this->stringFrom(parsed: $parsed, method: 'getReceivedAt'),
		];
	}//end fileOnCase()

	/**
	 * The message's subject, or the file's name when it has none.
	 *
	 * A saved message with no subject reads as the file it came from, which
	 * is the only name the handler who dragged it in would recognise. An
	 * empty title on the timeline reads as a message nobody wrote.
	 *
	 * @param SavedMailFile $file   The file.
	 * @param object        $parsed The parsed message.
	 *
	 * @return string
	 */
	private function titleOf(SavedMailFile $file, object $parsed): string {
		$subject = $this->stringFrom(parsed: $parsed, method: 'getSubject');
		if ($subject === '') {
			return $file->fileName;
		}

		return $subject;
	}//end titleOf()

	/**
	 * The file stays, and the reason says why nothing was read.
	 *
	 * @param string $reason The sentence a handler reads.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string} The outcome.
	 */
	private function kept(string $reason): array {
		return [
			'outcome' => self::OUTCOME_KEPT,
			'reason' => $reason,
			'subject' => '',
			'from' => '',
			'receivedAt' => '',
		];
	}//end kept()

	/**
	 * The first recipient the parser found, or ''.
	 *
	 * @param object $parsed The parsed message.
	 *
	 * @return string The recipient.
	 */
	private function firstRecipient(object $parsed): string {
		if (method_exists($parsed, 'getTo') === false) {
			return '';
		}

		$to = $parsed->getTo();
		if (is_array($to) === false) {
			return '';
		}

		foreach ($to as $recipient) {
			$value = trim((string)$recipient);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstRecipient()

	/**
	 * Read a string off the parsed message, whatever it answers.
	 *
	 * Duck-typed for the same reason the listeners are: this is another app's
	 * class and a type hint on it is a fatal on an instance that has no
	 * integriq.
	 *
	 * @param object $parsed The parsed message.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 */
	private function stringFrom(object $parsed, string $method): string {
		if (method_exists($parsed, $method) === false) {
			return '';
		}

		$value = $parsed->$method();

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end stringFrom()
}//end class
