<?php

/**
 * Telling the applicant that their case moved, when the law says we must.
 *
 * Awb 2:3 is a doorzendplicht: a bestuursorgaan that is not competent sends
 * the request on to the one that is, AND tells the sender it did. So a
 * handover declares whether it is a doorzending, and only that declaration
 * produces a message.
 *
 * 🔴 AN INTERNAL MOVE TELLS THE APPLICANT NOTHING, AND THAT IS THE
 * REQUIREMENT, NOT AN OVERSIGHT. Vergunningen handing a case to Toezicht is
 * one bestuursorgaan rearranging its own work. Mailing a citizen about it
 * teaches them that our messages are noise, and the next message they ignore
 * is the one with the deadline in it.
 *
 * 🔑 IT SENDS THROUGH THE MOMENTS THE CASE TYPE ALREADY DECLARES. The
 * acknowledgement built the whole path: a template, a rendered subject and
 * body in the declared language, and a router that puts it in the citizen's
 * berichtenbox. A second sender here would be a second thing to keep in step
 * with the berichtenbox, and the first divergence would be a citizen who was
 * told nothing while the record says they were.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\TermijnNotificationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announces a doorzending to the applicant, and says nothing about an internal move.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class DoorzendingNotifier {

	/**
	 * The template a doorzending is rendered from.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'doorzending';

	/**
	 * Constructor.
	 *
	 * @param TermijnNotificationService $notifications The renderer and the berichtenbox router.
	 * @param CaseContactDirectory       $contacts      The addresses registered on a case.
	 * @param LoggerInterface            $logger        Says why an announcement could not be made.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly TermijnNotificationService $notifications,
		private readonly CaseContactDirectory $contacts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Tell the applicant where the case went, if this handover is a doorzending.
	 *
	 * @param array<string, mixed> $case     The case that moved.
	 * @param array<string, mixed> $transfer The completed transfer record.
	 *
	 * @return array{announced: bool, reason?: string, payload?: array<string, mixed>}
	 *         Whether a message went out, and why not when it did not.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	public function announce(array $case, array $transfer): array {
		if ($this->isDoorzending(transfer: $transfer) === false) {
			return ['announced' => false, 'reason' => 'internal-move'];
		}

		$recipient = $this->addressOf(caseRow: $case);
		if ($recipient === '') {
			return $this->unaddressed(caseRow: $case);
		}

		$variables = $this->variablesFor(caseRow: $case, transfer: $transfer) + ['addressee' => ['address' => $recipient]];
		return $this->send(caseRow: $case, variables: $variables);
	}//end announce()

	/**
	 * The answer, and the warning, when the case carries no address.
	 *
	 * @param array<string, mixed> $caseRow The case that moved.
	 *
	 * @return array{announced: bool, reason: string} Not announced.
	 */
	private function unaddressed(array $caseRow): array {
		$this->logger->warning(
			'Dossiq doorzending: the case carries no address, so the applicant was not told',
			['caseId' => trim((string)($caseRow['id'] ?? ''))],
		);

		return ['announced' => false, 'reason' => 'no-address'];
	}//end unaddressed()

	/**
	 * The template variables naming the case, where it went and the language to say it in.
	 *
	 * @param array<string, mixed> $caseRow     The case that moved.
	 * @param array<string, mixed> $transfer The completed transfer record.
	 *
	 * @return array<string, string> The variables, the addressee still to be added.
	 */
	private function variablesFor(array $caseRow, array $transfer): array {
		return [
			'case' => trim((string)($caseRow['identifier'] ?? ($caseRow['id'] ?? ''))),
			'destination' => $this->destinationOf(transfer: $transfer),
			'locale' => $this->localeOf(caseRow: $caseRow),
		];
	}//end variablesFor()

	/**
	 * Send the notice to the addressee, or answer why it did not go.
	 *
	 * @param array<string, mixed> $caseRow      The case that moved.
	 * @param array<string, mixed> $variables The template variables, with the addressee.
	 *
	 * @return array{announced: bool, reason?: string, payload?: array<string, mixed>} The outcome.
	 */
	private function send(array $caseRow, array $variables): array {
		try {
			// The case row, so a portal requester gets it in the portal
			// inbox and the case records the result (REQ-WRN-002).
			$payload = $this->notifications->sendTermijnNotification(self::TEMPLATE, '', $variables['addressee']['address'], $variables, $caseRow);
		} catch (Throwable $e) {
			$context = ['exception' => $e->getMessage(), 'caseId' => trim((string)($caseRow['id'] ?? ''))];
			$this->logger->error('Dossiq doorzending: the applicant could not be told the case moved', $context);

			return ['announced' => false, 'reason' => 'dispatch-failed'];
		}

		return ['announced' => true, 'payload' => $payload];
	}//end send()

	/**
	 * Whether this transfer declared itself a doorzending.
	 *
	 * @param array<string, mixed> $transfer The transfer record.
	 *
	 * @return bool True when it did.
	 */
	private function isDoorzending(array $transfer): bool {
		return in_array(($transfer['doorzending'] ?? false), [true, 'true', 1, '1'], true);
	}//end isDoorzending()

	/**
	 * Where the case went, named the way the applicant would recognise it.
	 *
	 * @param array<string, mixed> $transfer The transfer record.
	 *
	 * @return string The receiving team or organisation.
	 */
	private function destinationOf(array $transfer): string {
		$team = trim((string)($transfer['targetTeam'] ?? ''));
		if ($team !== '') {
			return $team;
		}

		return trim((string)($transfer['targetOrganization'] ?? ''));
	}//end destinationOf()

	/**
	 * The first address registered on the case, or ''.
	 *
	 * @param array<string, mixed> $caseRow The case row.
	 *
	 * @return string The address.
	 */
	private function addressOf(array $caseRow): string {
		$addresses = $this->contacts->collectAddresses(caseData: $caseRow);
		if ($addresses !== []) {
			return (string)reset($addresses);
		}

		return trim((string)($caseRow['portalSubject'] ?? ''));
	}//end addressOf()

	/**
	 * The language the citizen is written to in.
	 *
	 * Read off the case rather than from IL10N, for the reason the
	 * acknowledgement records: IL10N serves the signed-in handler's interface
	 * language, and the reader here has no session at all.
	 *
	 * @param array<string, mixed> $caseRow The case row.
	 *
	 * @return string The locale, `nl` when the case declares none.
	 */
	private function localeOf(array $caseRow): string {
		$locale = trim((string)($caseRow['language'] ?? ''));
		if ($locale === '') {
			return 'nl';
		}

		return $locale;
	}//end localeOf()
}//end class
