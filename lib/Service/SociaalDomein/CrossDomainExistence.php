<?php

/**
 * Whether a household is already known to another domain, and nothing else.
 *
 * 🔴 THE SMALLEST ANSWER THAT HELPS IS THE WHOLE DESIGN (D-6). The question a
 * consulent actually has is narrow: is this household already known somewhere
 * else, so that I coordinate rather than duplicate. Purpose limitation between
 * Wmo, Jeugdwet and Participatiewet does not allow a 360 view of the household,
 * which is why gap row 5.4 asks for the wrong thing. It does allow three
 * facts: that an open case exists, in which domain, and who to call. Every
 * field beyond those three is a field somebody has to justify, so the
 * projection here builds its answer FIELD BY FIELD rather than filtering a row
 * down, which is the difference between a leak that needs a new `unset()` and
 * one that cannot happen: a property added to `wmoZaak` tomorrow appears in
 * nothing here.
 *
 * 🔴 THE GROUND IS CHOSEN BEFORE THE ANSWER, NOT RECORDED AFTER IT (D-7). A
 * ground filled in afterwards is a formality. Requiring it first makes the
 * lookup deliberate, and it is what makes the log mean something when the
 * person asks what was looked up about them.
 *
 * 🔴 THE LOG IS THE DELIVERABLE, NOT A SIDE EFFECT (D-8).
 * `sociaalDomeinAuditLog.authorisationGround` has been declared and unread
 * since the register was written, which is the defect gap row 5.18 names. So
 * the answer and the log entry are ONE act here rather than an event a listener
 * might be attached to: a listener that is not registered, or that throws, is
 * a lookup nobody can account for afterwards, and it looks exactly like a
 * lookup that never happened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
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
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;

/**
 * Answer, on a recorded ground, whether another domain holds an open case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class CrossDomainExistence {

	/**
	 * The schema the log is written to.
	 *
	 * @var string
	 */
	public const LOG_SCHEMA = 'sociaalDomeinAuditLog';

	/**
	 * What this act is called in the log.
	 *
	 * Its own value beside `read`, because reading a case and asking whether a
	 * case exists anywhere are not the same act and a subject access request
	 * has to be able to tell them apart.
	 *
	 * @var string
	 */
	public const ACTION = 'existence-lookup';

	/**
	 * The grounds a lookup may be made on.
	 *
	 * A CLOSED list, because an open text field is a field that fills up with
	 * "onderzoek" and answers nobody's question afterwards. Each of these is a
	 * ground somebody can be held to.
	 *
	 * @var array<string, string>
	 */
	public const GROUNDS = [
		'wettelijke-taak' => 'Carrying out a statutory task in this domain',
		'toestemming' => 'The person has consented to this lookup',
		'vitaal-belang' => 'A vital interest of the person or of another',
		'samenwerkingsafspraak' => 'A recorded cooperation agreement between the domains',
	];

	/**
	 * The three fields the answer carries, and there are no others.
	 *
	 * Written down as a constant so a test can assert the shape rather than
	 * enumerate what it happens to see: "these and nothing else" is the
	 * requirement, and a test that reads the keys off the answer would pass on
	 * an answer that grew a fourth.
	 *
	 * @var array<int, string>
	 */
	public const ANSWER_FIELDS = ['exists', 'domain', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store  The one reader and writer of these schemas.
	 * @param LoggerInterface    $logger The logger.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an open case exists in another domain, and who to contact.
	 *
	 * @param string $bsn       The person looked up.
	 * @param string $ownDomain The domain the caller works in, which is excluded.
	 * @param string $ground    The chosen ground, from {@see GROUNDS}.
	 * @param string $requester The uid of whoever is asking.
	 *
	 * @return array<int, array{exists: bool, domain: string, contact: string}> One entry per domain that holds one.
	 *
	 * @throws RefusedException When no ground is chosen, or the store cannot be read.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	public function lookUp(string $bsn, string $ownDomain, string $ground, string $requester): array {
		$this->assertGround(ground: $ground);

		$bsn = trim($bsn);
		if ($bsn === '') {
			throw new RefusedException(
				rule: 'lookup-needs-a-person',
				sentence: 'Say who you are looking up.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$found = [];
		foreach (SociaalDomeinStore::DOMAINS as $domain => $declaration) {
			if ($domain === $ownDomain) {
				continue;
			}

			// The BSN KEY comes from the domain's own declaration, because the
			// three schemas do not spell it the same way: a Jeugdwet case names
			// the juvenile in `jeugdigeBsn`. Filtering it on `bsn` answers a
			// clean "nothing here" rather than an error.
			$filters = [$declaration['bsnKey'] => $bsn];

			foreach ($this->store->rows(schema: $declaration['schema'], filters: $filters) as $row) {
				if ($this->isOpen(row: $row) === false) {
					continue;
				}

				// FIELD BY FIELD. Nothing of the row travels: not its title,
				// not its status, not its dates and not its number. A property
				// added to that schema tomorrow appears in nothing here.
				$found[] = [
					'exists' => true,
					'domain' => $domain,
					'contact' => $this->contactFor(row: $row, domain: $domain),
				];
				break;
			}
		}

		// The log is written in the same act as the answer, including when the
		// answer is that nothing exists: a lookup that found nothing is still a
		// lookup that happened, and it is exactly the one a person asking what
		// was looked up about them would otherwise never hear about.
		$this->log(
			bsn: $bsn,
			ground: $ground,
			requester: $requester,
			returned: $this->describe(found: $found)
		);

		return $found;
	}//end lookUp()

	/**
	 * What was looked up about one person, with the grounds and the dates.
	 *
	 * @param string $bsn The person.
	 *
	 * @return array<int, array<string, mixed>> The lookups, newest first.
	 *
	 * @throws RefusedException When the log cannot be read.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	public function lookupsAbout(string $bsn): array {
		$bsn = trim($bsn);
		if ($bsn === '') {
			return [];
		}

		$rows = [];
		foreach ($this->store->rows(schema: self::LOG_SCHEMA, filters: ['subjectBsn' => $bsn]) as $row) {
			if ((string)($row['action'] ?? '') !== self::ACTION) {
				continue;
			}

			$rows[] = [
				'moment' => (string)($row['moment'] ?? ''),
				'ground' => (string)($row['authorisationGround'] ?? ''),
				'groundLabel' => (self::GROUNDS[(string)($row['authorisationGround'] ?? '')] ?? ''),
				'requester' => (string)($row['employeeId'] ?? ''),
				'returned' => (string)($row['returned'] ?? ''),
			];
		}

		usort(
			$rows,
			static fn (array $a, array $b): int => strcmp($b['moment'], $a['moment'])
		);

		return $rows;
	}//end lookupsAbout()

	/**
	 * Refuse a lookup with no ground, or with one nobody can be held to.
	 *
	 * @param string $ground The chosen ground.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the ground is absent or unknown.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	public function assertGround(string $ground): void {
		$ground = trim($ground);
		if ($ground === '') {
			throw new RefusedException(
				rule: 'lookup-needs-a-ground',
				sentence: 'Choose the ground you are looking this person up on. '
					. 'The ground is recorded, and the person can ask to see it.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (array_key_exists($ground, self::GROUNDS) === false) {
			throw new RefusedException(
				rule: 'lookup-ground-not-recognised',
				sentence: 'That is not one of the grounds a cross-domain lookup can be made on.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertGround()

	/**
	 * Write the lookup to the audit log, with its ground.
	 *
	 * @param string $bsn       The person looked up.
	 * @param string $ground    The ground it was made on.
	 * @param string $requester Who asked.
	 * @param string $returned  What came back.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the log cannot be written.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	private function log(string $bsn, string $ground, string $requester, string $returned): void {
		$this->store->write(
			schema: self::LOG_SCHEMA,
			object: [
				// `caseId` is required by the schema and this act is not about
				// one case, so it carries the person instead of being left
				// blank: a required field filled with '' is a row the register
				// may refuse, and one filled with a lie is worse.
				'caseId' => $bsn,
				'subjectBsn' => $bsn,
				'employeeId' => $requester,
				'action' => self::ACTION,
				'moment' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
				'authorisationGround' => $ground,
				'returned' => $returned,
				'geraadpleegdeVelden' => self::ANSWER_FIELDS,
				'result' => 'succes',
			]
		);

		$this->logger->info(
			'CrossDomainExistence: a cross-domain existence lookup was made and logged',
			['requester' => $requester, 'ground' => $ground]
		);
	}//end log()

	/**
	 * What the answer was, in words the log can carry.
	 *
	 * @param array<int, array<string, mixed>> $found The answer.
	 *
	 * @return string The description.
	 */
	private function describe(array $found): string {
		if ($found === []) {
			return 'No open case in another domain.';
		}

		return 'An open case exists in: ' . implode(', ', array_column($found, 'domain')) . '.';
	}//end describe()

	/**
	 * Whether a domain case is open.
	 *
	 * A case with no readable state counts as OPEN. The alternative is to
	 * treat an unreadable state as closed, which would answer "nobody else is
	 * working with this household" about a household somebody is working with,
	 * and that is the one wrong answer this lookup must not give.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return boolean True when it is open.
	 */
	private function isOpen(array $row): bool {
		$status = strtolower(trim((string)($row['status'] ?? '')));
		if ($status === '') {
			return true;
		}

		// All three domain schemas spell their terminal state with the same
		// literal, so there is ONE word here rather than a list of guesses:
		// a guessed synonym that no schema uses would read as a guard that
		// works while doing nothing.
		return ($status !== SociaalDomeinStore::CLOSED_STATUS);
	}//end isOpen()

	/**
	 * Who to call about a case in another domain.
	 *
	 * The handler when the row names one, and otherwise the domain's own desk,
	 * because "a case exists and we cannot tell you who to ask" is an answer
	 * that helps nobody coordinate.
	 *
	 * @param array<string, mixed> $row    The case row.
	 * @param string               $domain The domain.
	 *
	 * @return string The contact.
	 */
	private function contactFor(array $row, string $domain): string {
		// `handlerId` is what all three schemas call it, with `districtTeam`
		// behind it because a case between handlers still has a team.
		foreach (['handlerId', 'districtTeam'] as $key) {
			$value = trim((string)($row[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return $domain;
	}//end contactFor()
}//end class
