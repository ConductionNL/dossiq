<?php

/**
 * CaseEndingActs golden test
 *
 * Pins what finish, abort, archive and unarchive read, write, log and answer,
 * for cases that end, cases that are refused at each guard, and collaborators
 * that answer nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseArchiveState;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for CaseEndingActs.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseEndingActs
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseEndingActsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any collaborator received, by parameter name.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A double whose listed methods record their call and answer as configured.
	 *
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, a closure over the named args, or 'refuse'.
	 *
	 * @return object The double.
	 */
	private function double(string $class, array $behaviour): object {
		$mock = $this->createMock($class);
		foreach ($behaviour as $method => $answer) {
			$names = array_map(
				static fn (\ReflectionParameter $param): string => $param->getName(),
				(new ReflectionMethod($class, $method))->getParameters()
			);
			$mock->method($method)->willReturnCallback(
				function (mixed ...$args) use ($class, $method, $names, $answer): mixed {
					$named = [];
					foreach ($args as $index => $value) {
						$named[$names[$index]] = $value;
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, $named];
					if ($answer === 'refuse') {
						throw new RefusedException(rule: $method . '-refused', sentence: 'No.', status: RefusedException::STATUS_FORBIDDEN);
					}

					if ($answer instanceof \Closure) {
						return $answer(...$named);
					}

					return $answer;
				}
			);
		}

		return $mock;
	}//end double()

	/**
	 * Run one act against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The behaviour per collaborator and the call.
	 *
	 * @return array<string, mixed> The answer or refusal, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$case = (array_key_exists('case', $world) === true ? $world['case'] : self::openCase());
		$store = $this->double(
			CaseStatusStore::class,
			[
				'loadCase' => $case,
				'saveCase' => static fn (array $case): array => $case,
				'writeStatusRecord' => ['id' => 'rec-1'],
			]
		);
		$results = $this->double(
			CaseResultWriter::class,
			($world['results'] ?? []) + [
				'resolveClosingResult' => 'result-1',
				'archivalFuture' => ['archiveNomination' => 'vernietigen', 'archiveActionDate' => '2036-01-01'],
				'resultTypeChoices' => [['id' => 'rt-1', 'name' => 'Verleend']],
			]
		);
		$statuses = $this->double(StatusTypeLookup::class, ['rowsOf' => ($world['rows'] ?? self::rows())]);
		$gate = $this->double(LifecycleActorGate::class, ['require' => ($world['gate'] ?? null)]);
		$incompleteness = $this->double(CaseIncompleteness::class, ['requireComplete' => ($world['incomplete'] ?? null)]);
		$journal = $this->double(
			CaseJournal::class,
			[
				'append' => static fn (array $case, array $entry): array => $case + ['journalled' => $entry],
				'latest' => ($world['latest'] ?? ['type' => 'finish', 'resultType' => ' rt-1 ', 'at' => '2026-01-02', 'by' => 'anna', 'reason' => 'done']),
			]
		);
		$archive = $this->double(
			CaseArchiveState::class,
			($world['archive'] ?? []) + [
				'isArchived' => true,
				'mark' => ['archived' => '2026-03-04'],
				'clear' => ['archived' => null],
			]
		);

		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logs): void {
				$logs[] = [$message, $context];
			}
		);

		$acts = new CaseEndingActs(
			store: $store,
			results: $results,
			statuses: $statuses,
			gate: $gate,
			incompleteness: $incompleteness,
			journal: $journal,
			logger: $logger,
			archiveState: $archive,
		);
		try {
			$answer = ($world['call'])($acts);
		} catch (RefusedException $refused) {
			$answer = ['refused' => $refused->getRule(), 'sentence' => $refused->getSentence(), 'status' => $refused->getStatus()];
		}

		$observed = ['answer' => $answer, 'calls' => $this->calls, 'logs' => $logs];
		$today = (new DateTimeImmutable())->format('Y-m-d');

		return json_decode(str_replace($today, '<today>', (string)json_encode($observed)), true);
	}//end observe()

	/**
	 * An open case in its second phase.
	 *
	 * @return array<string, mixed> The case.
	 */
	private static function openCase(): array {
		return ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 's-2'];
	}//end openCase()

	/**
	 * The case type's statuses, out of order, in every id shape.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private static function rows(): array {
		return [
			['id' => 's-end-b', 'order' => 9, 'isFinal' => 'true', 'name' => 'Afgewezen'],
			['uuid' => 's-3', 'order' => '3', 'title' => 'Beoordelen'],
			['id' => 's-1', 'order' => 1, 'name' => 'Ontvangen'],
			['@self' => ['id' => 's-4'], 'order' => 4],
			['id' => 's-2', 'order' => 2, 'name' => 'Intake'],
			['id' => 's-end-a', 'order' => 8, 'isFinal' => 1, 'name' => 'Verleend'],
			['id' => 's-x', 'name' => 'Ongeordend'],
			['id' => 's-final-unordered', 'isFinal' => true],
			['id' => 's-not-final', 'order' => 5, 'isFinal' => 'false', 'name' => 'Wacht'],
			['id' => 's-5', 'order' => 6, 'isFinal' => '1', 'name' => 'Ook eind'],
		];
	}//end rows()

	/**
	 * Every act across its guards and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenCaseEndingActs(): void {
		$finish = static fn (string $reason = 'Klaar', string $result = 'rt-1', string $to = ''): \Closure => static fn (CaseEndingActs $acts): array => $acts->finish(
			caseId: 'case-1',
			reason: $reason,
			resultTypeId: $result,
			toStatus: $to,
		);
		$abort = static fn (string $reason = 'Ingetrokken', string $result = 'rt-2', string $to = ''): \Closure => static fn (CaseEndingActs $acts): array => $acts->abort(
			caseId: 'case-1',
			reason: $reason,
			resultTypeId: $result,
			toStatus: $to,
		);
		$archive = static fn (string $reason = 'Afgehandeld'): \Closure => static fn (CaseEndingActs $acts): array => $acts->archive(caseId: 'case-1', reason: $reason);
		$unarchive = static fn (string $reason = 'Per abuis'): \Closure => static fn (CaseEndingActs $acts): array => $acts->unarchive(caseId: 'case-1', reason: $reason);
		$ended = ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 's-end-a', CaseEndingActs::ENDING_FIELD => 'finish', 'endDate' => '2026-02-03'];

		$scenarios = [
			'finish' => ['call' => $finish()],
			'finish, named terminal status' => ['call' => $finish(to: 's-end-b')],
			'finish, named terminal status in uuid shape' => ['call' => $finish(to: 's-5')],
			'finish, named status not terminal' => ['call' => $finish(to: 's-3')],
			'finish, named status unknown' => ['call' => $finish(to: 'nope')],
			'finish, no result type' => ['call' => $finish(result: '')],
			'finish, result resolves to nothing' => ['results' => ['resolveClosingResult' => null], 'call' => $finish()],
			'finish, result refused' => ['results' => ['resolveClosingResult' => 'refuse'], 'call' => $finish()],
			'finish, empty archival future' => ['results' => ['archivalFuture' => []], 'call' => $finish()],
			'finish, from the last phase' => ['case' => ['caseType' => 'ct-1', 'status' => 's-5'] + self::openCase(), 'call' => $finish()],
			'finish, from a status not in the type' => ['case' => ['status' => 'elsewhere'] + self::openCase(), 'call' => $finish()],
			'finish, case without type or status' => ['case' => ['id' => 'case-1'], 'call' => $finish()],
			'finish, no terminal status' => ['rows' => [['id' => 's-1', 'order' => 1]], 'call' => $finish()],
			'finish, only an unordered terminal status' => ['rows' => [['id' => 's-1', 'order' => 1], ['id' => 's-z', 'isFinal' => true]], 'call' => $finish()],
			'finish, blank reason' => ['call' => $finish(reason: '  ')],
			'finish, case not found' => ['case' => null, 'call' => $finish()],
			'finish, gate refuses' => ['gate' => 'refuse', 'call' => $finish()],
			'finish, incomplete' => ['incomplete' => 'refuse', 'call' => $finish()],
			'abort' => ['call' => $abort()],
			'abort, incomplete still works' => ['incomplete' => 'refuse', 'call' => $abort()],
			'abort, no result type' => ['call' => $abort(result: '')],
			'abort, named terminal status' => ['call' => $abort(to: 's-end-b')],
			'abort, gate refuses' => ['gate' => 'refuse', 'call' => $abort()],
			'abort, blank reason' => ['call' => $abort(reason: '')],
			'archive' => ['case' => $ended, 'call' => $archive()],
			'archive, no end date' => ['case' => ['endDate' => ''] + $ended, 'call' => $archive()],
			'archive, end date missing' => ['case' => array_diff_key($ended, ['endDate' => 1]), 'call' => $archive()],
			'archive, no action date' => ['case' => $ended, 'results' => ['archivalFuture' => ['archiveNomination' => 'bewaren']], 'call' => $archive()],
			'archive, empty action date' => ['case' => $ended, 'results' => ['archivalFuture' => ['archiveNomination' => 'bewaren', 'archiveActionDate' => '']], 'call' => $archive()],
			'archive, empty future' => ['case' => $ended, 'results' => ['archivalFuture' => []], 'call' => $archive()],
			'archive, no ending in the journal' => ['case' => $ended, 'latest' => [], 'call' => $archive()],
			'archive, marker answers nothing' => ['case' => $ended, 'archive' => ['mark' => []], 'call' => $archive()],
			'archive, open case' => ['call' => $archive()],
			'archive, blank ending field' => ['case' => [CaseEndingActs::ENDING_FIELD => ' '] + $ended, 'call' => $archive()],
			'archive, blank reason' => ['case' => $ended, 'call' => $archive(reason: ' ')],
			'archive, gate refuses' => ['case' => $ended, 'gate' => 'refuse', 'call' => $archive()],
			'archive, case not found' => ['case' => null, 'call' => $archive()],
			'unarchive' => ['case' => $ended, 'call' => $unarchive()],
			'unarchive, not archived' => ['case' => $ended, 'archive' => ['isArchived' => false], 'call' => $unarchive()],
			'unarchive, blank reason' => ['case' => $ended, 'call' => $unarchive(reason: '')],
			'unarchive, gate refuses' => ['case' => $ended, 'gate' => 'refuse', 'call' => $unarchive()],
			'unarchive, platform refuses' => ['case' => $ended, 'archive' => ['clear' => 'refuse'], 'call' => $unarchive()],
			'ending of' => ['call' => static fn (CaseEndingActs $acts): array => $acts->endingOf(case: $ended)],
			'ending of, none' => ['latest' => [], 'call' => static fn (CaseEndingActs $acts): array => $acts->endingOf(case: $ended)],
			'ending of, partial entry' => ['latest' => ['type' => 'abort'], 'call' => static fn (CaseEndingActs $acts): array => $acts->endingOf(case: $ended)],
			'result choices' => ['call' => static fn (CaseEndingActs $acts): array => $acts->resultChoicesOn(case: $ended)],
			'result choices, no type' => ['call' => static fn (CaseEndingActs $acts): array => $acts->resultChoicesOn(case: [])],
			'is ended' => ['call' => static fn (CaseEndingActs $acts): array => [$acts->isEnded(case: $ended), $acts->isEnded(case: []), $acts->isEnded(case: [CaseEndingActs::ENDING_FIELD => "\t"])]],
		];

		$observed = [];
		foreach ($scenarios as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden('case-ending-acts', $observed);
	}//end testGoldenCaseEndingActs()
}//end class
