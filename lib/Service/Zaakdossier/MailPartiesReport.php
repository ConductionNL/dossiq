<?php

/**
 * Dossiq mail parties report
 *
 * Counts the collected mail on a case per sender address, per recipient
 * address (to and cc) and per mail domain, plus the mails whose headers could
 * not be read (REQ-WRR-004). It reads `provenance.mail` through
 * CaseDocumentProvenance::mailForCase(), so it works for any case type that
 * collects mail; nothing in it knows which procedure the case follows.
 *
 * Every count is per mail: an address named twice in one mail, or a domain
 * that is both sender and recipient of one mail, counts once for that mail.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Kernel\Contracts\MailPartiesReportInterface;

/**
 * The parties of a case's collected mail, counted.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
 */
class MailPartiesReport implements MailPartiesReportInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseDocumentProvenance $provenance Reads the mail header fields of the case's documents.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseDocumentProvenance $provenance,
	) {
	}//end __construct()

	/**
	 * The counts for one case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{
	 *     mails: int,
	 *     unreadable: int,
	 *     senders: list<array{address: string, count: int}>,
	 *     recipients: list<array{address: string, count: int}>,
	 *     domains: list<array{domain: string, count: int}>
	 * } Each list sorted by count, highest first, then by name.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
	 */
	public function forCase(string $caseId): array {
		$mails = $this->provenance->mailForCase(caseId: $caseId);
		$tally = ['unreadable' => 0, 'senders' => [], 'recipients' => [], 'domains' => []];
		foreach ($mails as $mail) {
			$tally = $this->counted(tally: $tally, mail: $mail);
		}

		return [
			'mails' => count($mails),
			'unreadable' => $tally['unreadable'],
			'senders' => $this->rows(counts: $tally['senders'], key: 'address'),
			'recipients' => $this->rows(counts: $tally['recipients'], key: 'address'),
			'domains' => $this->rows(counts: $tally['domains'], key: 'domain'),
		];
	}//end forCase()

	/**
	 * The tally with one more mail counted: as unreadable, or by its sender, recipients and their domains.
	 *
	 * @param array<string, mixed> $tally The counts so far: unreadable, then senders, recipients and domains by name.
	 * @param array<string, mixed> $mail  One mail's provenance.
	 *
	 * @return array<string, mixed> The counts, in the same shape.
	 */
	private function counted(array $tally, array $mail): array {
		if ($mail['unreadable'] === true) {
			$tally['unreadable']++;
			return $tally;
		}

		$sender = (string)$mail['from'];
		$to     = array_values(array_unique(array_merge((array)$mail['to'], (array)$mail['cc'])));
		if ($sender !== '') {
			$tally['senders'] = self::plusOne(counts: $tally['senders'], keys: [$sender]);
		}

		$tally['recipients'] = self::plusOne(counts: $tally['recipients'], keys: $to);
		$tally['domains']    = self::plusOne(counts: $tally['domains'], keys: $this->domainsOf(addresses: array_merge([$sender], $to)));

		return $tally;
	}//end counted()

	/**
	 * The counts with one added for each key.
	 *
	 * @param array<string, int> $counts The counts so far.
	 * @param array<int, string> $keys   The keys to count once each.
	 *
	 * @return array<string, int> The counts.
	 */
	private static function plusOne(array $counts, array $keys): array {
		foreach ($keys as $key) {
			$counts[$key] = (($counts[$key] ?? 0) + 1);
		}

		return $counts;
	}//end plusOne()

	/**
	 * The report as CSV: one line per party, its kind, and its count; unreadable last.
	 *
	 * @param array<string, mixed> $report The report forCase() answered.
	 *
	 * @return string The CSV, with a header line.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-the-collection-reported-by-sender-recipient-and-domain-req-wrr-004
	 */
	public function toCsv(array $report): string {
		$stream = fopen('php://memory', 'r+');
		fputcsv($stream, ['kind', 'party', 'count'], escape: '');
		foreach (['senders' => 'sender', 'recipients' => 'recipient', 'domains' => 'domain'] as $list => $kind) {
			foreach (($report[$list] ?? []) as $row) {
				fputcsv($stream, [$kind, (string)($row['address'] ?? $row['domain'] ?? ''), (int)$row['count']], escape: '');
			}
		}

		fputcsv($stream, ['unreadable', '', (int)($report['unreadable'] ?? 0)], escape: '');
		rewind($stream);
		$csv = (string)stream_get_contents($stream);
		fclose($stream);

		return $csv;
	}//end toCsv()

	/**
	 * The distinct domains of the given addresses.
	 *
	 * @param list<string> $addresses The addresses, '' allowed.
	 *
	 * @return list<string>
	 */
	private function domainsOf(array $addresses): array {
		$domains = [];
		foreach ($addresses as $address) {
			$atSign = strrpos($address, '@');
			if ($atSign !== false) {
				$domains[substr($address, ($atSign + 1))] = true;
			}
		}

		return array_keys($domains);
	}//end domainsOf()

	/**
	 * Counts as sorted rows.
	 *
	 * @param array<string, int> $counts Count per name.
	 * @param string             $key    The row key the name goes under.
	 *
	 * @return list<array<string, int|string>>
	 */
	private function rows(array $counts, string $key): array {
		uksort(
			$counts,
			static fn (string $left, string $right): int => [$counts[$right], $left] <=> [$counts[$left], $right]
		);

		$rows = [];
		foreach ($counts as $name => $count) {
			$rows[] = [$key => (string)$name, 'count' => $count];
		}

		return $rows;
	}//end rows()
}//end class
