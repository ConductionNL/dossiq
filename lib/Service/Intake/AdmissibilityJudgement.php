<?php

/**
 * Intake ends with a verdict, and an inadmissible one ends the case.
 *
 * Dimpact ends intake with an ontvankelijkheid judgement. The failure that
 * avoids is a case sitting in an intake phase nobody will ever work, which is
 * how a gemeente accumulates a backlog it cannot see: the case is open, the
 * term is running, and no queue lists it because no phase owns it.
 *
 * 🔴 INADMISSIBLE IS A CLOSE, NOT A DEAD PHASE (D-3). The verdict goes through
 * the ordinary close act with a result type of niet-ontvankelijk, so the case
 * inherits the retention rule and the archival consequence that result type
 * carries, exactly as every other ending does. A status of its own would have
 * been a fourth way to end a case with a fourth set of consequences to keep in
 * step.
 *
 * 🔴 THE JUDGE IS RECORDED ON THE CASE, NOT ONLY IN A LOG. The applicant may
 * object to the verdict, and the file then has to show who reached it and when.
 * A log line is not a file.
 *
 * 🔑 THE APPLICANT IS TOLD THROUGH THE DECLARED MOMENT, not through a message
 * this class writes. `ontvangstbevestiging` already owns "when does this case
 * type write to the applicant", and a second mechanism beside it is a second
 * place to switch a message off.
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
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\AdmissibilityJudgementInterface;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\Support\StoredArray;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the admissibility declaration, and ends the case on an inadmissible verdict.
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */
class AdmissibilityJudgement implements AdmissibilityJudgementInterface {

	/**
	 * The case type property holding the declaration.
	 *
	 * @var string
	 */
	public const DECLARATION = 'admissibilityJudgement';

	/**
	 * The case property holding the verdict.
	 *
	 * @var string
	 */
	public const RECORD = 'admissibility';

	/**
	 * The verdict that ends the case.
	 *
	 * @var string
	 */
	public const INADMISSIBLE = 'inadmissible';

	/**
	 * The verdict that lets the case move on.
	 *
	 * @var string
	 */
	public const ADMISSIBLE = 'admissible';

	/**
	 * The moment of `notificationMoments` that tells the applicant.
	 *
	 * @var string
	 */
	public const MOMENT = 'case-inadmissible';

	/**
	 * The template the applicant's message is rendered from.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'niet-ontvankelijk';

	/**
	 * What a declaration says when the case type says nothing.
	 *
	 * Off. An acknowledgement of receipt is the law's and applies whether or
	 * not anybody configured it; an admissibility judgement is this
	 * organisation's own rule about its own intake, and a product that asks
	 * every case type for a verdict is a product nobody uses.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = [
		'enabled' => false,
		'inadmissibleResultType' => '',
		'moment' => self::MOMENT,
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver         $caseTypes The effective case type, parents included.
	 * @param CaseStatusStore          $store     Reads the case the verdict is about.
	 * @param CaseEndingActs           $endings   The ordinary close act.
	 * @param ApplicantMessage         $letters   Sends the message the declared moment owes the applicant.
	 * @param LoggerInterface          $logger    Says why a verdict could not be recorded.
	 * @param IL10N                    $l10n      Words the publication warnings in the reader's language.
	 */
	public function __construct(
		private readonly CaseTypeResolver $caseTypes,
		private readonly CaseStatusStore $store,
		private readonly CaseEndingActs $endings,
		private readonly ApplicantMessage $letters,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The declaration on a case type, with the defaults filled in.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{enabled: bool, inadmissibleResultType: string, moment: string} The declaration.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function declarationFor(array $caseType): array {
		$declared = StoredArray::from(value: ($caseType[self::DECLARATION] ?? null));

		$moment = trim((string)($declared['moment'] ?? ''));
		if ($moment === '') {
			$moment = (string)self::DEFAULTS['moment'];
		}

		return [
			'enabled' => (($declared['enabled'] ?? false) === true),
			'inadmissibleResultType' => trim((string)($declared['inadmissibleResultType'] ?? '')),
			'moment' => $moment,
		];
	}//end declarationFor()

	/**
	 * Whether intake of this case type ends with a judgement.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return bool True when a verdict is asked for.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function appliesTo(array $caseType): bool {
		return $this->declarationFor(caseType: $caseType)['enabled'];
	}//end appliesTo()

	/**
	 * Whether this case type tells the applicant about an inadmissible verdict.
	 *
	 * A moment that is declared and switched off is a message somebody decided
	 * not to send, which is a legitimate choice and not the same as a moment
	 * nobody declared. Both answer false; only the second warns at publication.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return bool True when the moment is declared and on.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function tellsTheApplicant(array $caseType): bool {
		return $this->letters->declares(
			caseType: $caseType,
			moment: $this->declarationFor(caseType: $caseType)['moment'],
		);
	}//end tellsTheApplicant()

	/**
	 * What publishing this case type should say out loud about the judgement.
	 *
	 * A warning and not a refusal, the same trade the acknowledgement makes: a
	 * case type may genuinely judge admissibility without writing to the
	 * applicant. What must not happen is the result type being absent, because
	 * a close with no result is a case nobody can read afterwards.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The warnings, empty when there is nothing to say.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function publicationWarnings(array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false) {
			return [];
		}

		$warnings = [];

		if ($declaration['inadmissibleResultType'] === '') {
			$warnings[] = $this->l10n->t('This case type judges admissibility at intake and names no result for an inadmissible '
				. 'aanvraag. Name one, or the close has no outcome to record.');
		}

		if ($this->tellsTheApplicant(caseType: $caseType) === false) {
			$warnings[] = $this->l10n->t(
				'This case type closes an inadmissible aanvraag without telling the applicant. Add the moment %s to its automatic messages.',
				[$declaration['moment']]
			);
		}

		return $warnings;
	}//end publicationWarnings()

	/**
	 * Record the verdict an intake worker reached, and act on it.
	 *
	 * An admissible verdict is recorded and nothing else happens: moving the
	 * case on is the ordinary transition the phase already offers, and doing it
	 * here would be a second mover of the same case.
	 *
	 * @param string $caseId  The case UUID.
	 * @param string $verdict `admissible` or `inadmissible`.
	 * @param string $reason  Why, recorded on the case and carried into the close.
	 * @param string $judgedBy The uid of the intake worker.
	 *
	 * @return array<string, mixed> What was recorded, and what the close did.
	 *
	 * @throws RefusedException When the case type asks for no verdict, the verdict is
	 *                          not one of the two, the reason is empty, or the case
	 *                          type names no result for an inadmissible aanvraag.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function judge(string $caseId, string $verdict, string $reason, string $judgedBy): array {
		$verdict = trim($verdict);
		$reason = trim($reason);
		$this->assertJudgeable(verdict: $verdict, reason: $reason);

		$case = $this->caseOf(caseId: $caseId);
		$declaration = $this->declaredOn(case: $case);

		$record = [
			'verdict' => $verdict,
			'judgedBy' => $judgedBy,
			'judgedOn' => (new DateTimeImmutable())->format('c'),
			'reason' => $reason,
		];

		// 🔴 THE VERDICT IS WRITTEN BEFORE THE CLOSE, AND THAT ORDER MATTERS.
		// The close can be refused: a case missing its required data is refused
		// by the incompleteness rule, naming the field. Writing the judge
		// afterwards would lose the verdict on exactly the cases where somebody
		// later asks who reached it.
		$case[self::RECORD] = $record;
		$this->store->saveCase(case: $case);

		if ($verdict === self::ADMISSIBLE) {
			return ['verdict' => $verdict, 'record' => $record, 'closed' => false];
		}

		return $this->closeInadmissible(
			judgement: ['caseId' => $caseId, 'case' => $case, 'declaration' => $declaration, 'record' => $record]
		);
	}//end judge()

	/**
	 * Refuse a verdict that is not one of the two, or that gives no reason.
	 *
	 * @param string $verdict The trimmed verdict.
	 * @param string $reason  The trimmed reason.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the verdict is unknown or the reason is empty.
	 */
	private function assertJudgeable(string $verdict, string $reason): void {
		if (in_array($verdict, [self::ADMISSIBLE, self::INADMISSIBLE], true) === false) {
			throw new RefusedException(
				rule: 'admissibility-verdict-unknown',
				sentence: 'Say whether the aanvraag is ontvankelijk or niet-ontvankelijk.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if ($reason === '') {
			throw new RefusedException(
				rule: 'admissibility-reason-required',
				sentence: 'Write down why. The applicant may object to this verdict, and the '
					. 'file has to say what it was based on.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertJudgeable()

	/**
	 * The declaration on the case's case type, refused when it asks for no verdict.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array{enabled: bool, inadmissibleResultType: string, moment: string} The declaration.
	 *
	 * @throws RefusedException When the case type does not end intake with a judgement.
	 */
	private function declaredOn(array $case): array {
		$declaration = $this->declarationFor(caseType: $this->caseTypeOf(case: $case));
		if ($declaration['enabled'] === false) {
			throw new RefusedException(
				rule: 'admissibility-not-declared',
				sentence: 'This case type does not end intake with an admissibility judgement.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $declaration;
	}//end declaredOn()

	/**
	 * Close a case judged inadmissible with the declared result, then tell the applicant.
	 *
	 * @param array<string, mixed> $judgement The recorded verdict: caseId, case, declaration and record.
	 *
	 * @return array<string, mixed> What was recorded, what the close did and what happened to the message.
	 *
	 * @throws RefusedException When the case type names no result for an inadmissible aanvraag.
	 */
	private function closeInadmissible(array $judgement): array {
		$declaration = $judgement['declaration'];
		$record = $judgement['record'];
		if ($declaration['inadmissibleResultType'] === '') {
			throw new RefusedException(
				rule: 'admissibility-result-not-declared',
				sentence: 'This case type names no result for an inadmissible aanvraag, so the '
					. 'case cannot be closed with one. Name it on the case type first.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$closed = $this->endings->finish(
			caseId: $judgement['caseId'],
			reason: $record['reason'],
			resultTypeId: $declaration['inadmissibleResultType'],
		);

		$told = $this->tellApplicant(judgement: $judgement);

		return [
			'verdict' => $record['verdict'],
			'record' => $record,
			'closed' => true,
			'close' => $closed,
			// What happened to the message, reported rather than assumed, so a
			// surface can say "and the applicant was not told, because the case
			// carries no address" instead of leaving it to be discovered.
			'applicantTold' => $told['sent'],
			'applicantMessage' => $told,
			'moment' => $declaration['moment'],
		];
	}//end closeInadmissible()

	/**
	 * Tell the applicant through the declared moment, after the close.
	 *
	 * @param array<string, mixed> $judgement The recorded verdict: caseId, case, declaration and record.
	 *
	 * @return array<string, mixed> What the message did, with `sent`.
	 */
	private function tellApplicant(array $judgement): array {
		// The applicant is told AFTER the close, and a message that could not
		// go out does not undo it. A case left open because the letter failed
		// is the dead intake phase this verdict exists to prevent, and it
		// would be one nobody could see, because the verdict is recorded and
		// the case still sits in intake.
		$told = $this->letters->send(
			case: $judgement['case'],
			caseType: $this->caseTypeOf(case: $judgement['case']),
			moment: $judgement['declaration']['moment'],
			template: self::TEMPLATE,
			context: ['reason' => $judgement['record']['reason']],
		);

		$this->logger->info(
			'Dossiq admissibility: case {case} closed as niet-ontvankelijk',
			['case' => $judgement['caseId'], 'judgedBy' => $judgement['record']['judgedBy'], 'applicantTold' => $told['sent']],
		);

		return $told;
	}//end tellApplicant()

	/**
	 * The stored case, or a refusal when it cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When the case cannot be found.
	 */
	private function caseOf(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end caseOf()

	/**
	 * The effective case type of a case, or an empty array.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array<string, mixed> The effective case type.
	 */
	private function caseTypeOf(array $case): array {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		try {
			return $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq admissibility: the case type could not be read',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()],
			);

			return [];
		}
	}//end caseTypeOf()
}//end class
