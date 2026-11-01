<?php

/**
 * CaseTransferService golden test
 *
 * Runs every public method over a set of store, consent, idempotency and
 * custody behaviours, and compares every collaborator call (by parameter
 * name), every log line and every answer with a recorded golden file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Tests\Support\MakesRecordedCaseTransfers;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden test for CaseTransferService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\CaseTransferService
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @covers \OCA\Dossiq\Service\CaseTransfer\TransferStore
 */
class CaseTransferServiceGoldenTest extends TestCase {
	use MakesRecordedCaseTransfers;
	use MatchesGoldenFiles;

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$initiate = [
			'caseId' => 'case-1',
			'sourceOrganization' => 'org-a',
			'targetOrganization' => 'org-b',
			'reason' => 'moving',
			'requestedDate' => '2026-11-01',
			'initiatedBy' => 'alice',
		];
		$remote = $initiate + ['remoteCloudId' => 'partner@remote.example'];
		$keyed = ['precheck' => ['key' => 'key-1', 'answer' => null]];
		$trail = [['event' => 'initiated', 'actor' => 'bob', 'actorType' => 'local', 'cloudId' => '', 'timestamp' => '2026-10-01T10:00:00+00:00']];
		$pending = ['id' => 't-1', 'caseId' => 'case-1', 'targetOrganization' => 'org-b', 'reason' => 'moving', 'initiatedBy' => 'bob', 'status' => 'pending', 'custodyAuditTrail' => $trail];
		$with = static fn (array $fields): array => ['find' => ['t-1' => $fields + $pending]];
		$found = $with([]);
		$accept = ['transferId' => 't-1'];
		$acceptRemote = $accept + ['remoteCloudId' => 'partner@remote.example'];
		$reject = ['transferId' => 't-1', 'rejectionReason' => 'not ours'];
		$rejectRemote = $reject + ['remoteCloudId' => 'partner@remote.example'];

		return [
			'initiate / local' => ['initiateTransfer', $initiate, []],
			'initiate / default initiator' => ['initiateTransfer', array_diff_key($initiate, ['initiatedBy' => 1]), []],
			'initiate / no object service' => ['initiateTransfer', $initiate, ['noObjectService' => true]],
			'initiate / consent refused' => ['initiateTransfer', $initiate, ['consent' => ['allowed' => false, 'rule' => 'consent-missing', 'sentence' => 'No consent.']]],
			'initiate / empty remote' => ['initiateTransfer', $initiate + ['remoteCloudId' => ''], []],
			'initiate / federated' => ['initiateTransfer', $remote, $keyed],
			'initiate / federated, uuid key' => ['initiateTransfer', $remote, $keyed + ['idKey' => 'uuid']],
			'initiate / federated, id and uuid' => ['initiateTransfer', $remote, $keyed + ['idKey' => 'both']],
			'initiate / federated, no id' => ['initiateTransfer', $remote, $keyed + ['idKey' => 'none']],
			'initiate / federated, already there' => ['initiateTransfer', $remote, ['precheck' => ['key' => 'key-1', 'answer' => ['id' => 't-0', 'status' => 'pending']]]],
			'initiate / federated, mint fails' => ['initiateTransfer', $remote, $keyed + ['mint' => null]],
			'initiate / other scope' => ['initiateTransfer', $initiate, ['scope' => [11, 13]]],
			'accept / no object service' => ['acceptTransfer', $accept, ['noObjectService' => true]],
			'accept / not found' => ['acceptTransfer', $accept, []],
			'accept / already accepted' => ['acceptTransfer', $accept, $with(['status' => 'accepted'])],
			'accept / after a reject' => ['acceptTransfer', $accept, $with(['status' => 'rejected'])],
			'accept / no status' => ['acceptTransfer', $accept, ['find' => ['t-1' => ['id' => 't-1']]]],
			'accept / local' => ['acceptTransfer', $accept, $found],
			'accept / remote' => ['acceptTransfer', $acceptRemote, $found],
			'accept / custody refuses' => ['acceptTransfer', $accept, $found + ['custodyRefuses' => true]],
			'accept / remote, custody refuses' => ['acceptTransfer', $acceptRemote, $found + ['custodyRefuses' => true]],
			'accept / save answers an array' => ['acceptTransfer', $accept, $found + ['saveAs' => 'array']],
			'accept / find answers an array' => ['acceptTransfer', $accept, $found + ['findAs' => 'array']],
			'accept / bare pending record' => ['acceptTransfer', $accept, ['find' => ['t-1' => ['status' => 'pending']]]],
			'accept / other scope' => ['acceptTransfer', $accept, $found + ['scope' => [11, 13]]],
			'reject / local' => ['rejectTransfer', $reject, $found],
			'reject / remote' => ['rejectTransfer', $rejectRemote, $found],
			'reject / custody would refuse' => ['rejectTransfer', $reject, $found + ['custodyRefuses' => true]],
			'reject / already rejected' => ['rejectTransfer', $reject, $with(['status' => 'rejected'])],
			'reject / after an accept' => ['rejectTransfer', $reject, $with(['status' => 'accepted'])],
			'reject / not found' => ['rejectTransfer', $reject, []],
			'reject / no object service' => ['rejectTransfer', $reject, ['noObjectService' => true]],
			'case id / found' => ['getCaseIdForTransfer', $accept, $found],
			'case id / numeric' => ['getCaseIdForTransfer', $accept, ['find' => ['t-1' => ['caseId' => 42]]]],
			'case id / as array' => ['getCaseIdForTransfer', $accept, $found + ['findAs' => 'array']],
			'case id / without case' => ['getCaseIdForTransfer', $accept, ['find' => ['t-1' => ['id' => 't-1']]]],
			'case id / not found' => ['getCaseIdForTransfer', $accept, []],
			'case id / find throws' => ['getCaseIdForTransfer', $accept, ['find' => ['t-1' => 'throw']]],
			'case id / no object service' => ['getCaseIdForTransfer', $accept, ['noObjectService' => true]],
			'case id / no register' => ['getCaseIdForTransfer', $accept, $found + ['scope' => [0, 7]]],
			'case id / no schema' => ['getCaseIdForTransfer', $accept, $found + ['scope' => [5, 0]]],
			'resolve token' => ['resolveFederatedTransferShare', ['shareToken' => 'tok', 'transferId' => 't-1'], []],
			'resolve token / refused' => ['resolveFederatedTransferShare', ['shareToken' => 'tok', 'transferId' => 't-1'], ['resolve' => null]],
			'hand to team' => ['handToTeam', ['caseId' => 'case-1', 'targetTeam' => 'team-b', 'reason' => 'r', 'initiatedBy' => 'alice'], []],
			'hand to team / doorzending' => ['handToTeam', ['caseId' => 'case-1', 'targetTeam' => 'team-b', 'reason' => 'r', 'initiatedBy' => 'alice', 'doorzending' => true], []],
			'hand to team / custody refuses' => ['handToTeam', ['caseId' => 'case-1', 'targetTeam' => 'team-b', 'reason' => 'r', 'initiatedBy' => 'alice'], ['custodyRefuses' => true]],
			'accept handover' => ['acceptHandover', ['transferId' => 'h-1', 'acceptedBy' => 'carol'], []],
			'refuse handover' => ['refuseHandover', ['transferId' => 'h-1', 'reason' => 'no', 'refusedBy' => 'carol'], []],
			'outstanding handovers' => ['outstandingHandovers', ['team' => 'team-a'], []],
		];
	}//end cases()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $behaviour]) {
			$service = $this->recordedTransferService(behaviour: $behaviour);
			try {
				$answer = $service->$method(...$args);
			} catch (Throwable $e) {
				$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'case-transfer-service', value: $this->withoutClock(value: $actual));
	}//end testEveryCaseMatchesTheGoldenRecord()

	/**
	 * Replace the moments the service stamps with a marker.
	 *
	 * @param array<string, mixed> $value The recorded cases.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutClock(array $value): array {
		$json = (string)json_encode($value);
		$today = date('Y-m-d');
		$json = (string)preg_replace('/' . $today . 'T\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<now>', $json);
		return (array)json_decode($json, true);
	}//end withoutClock()
}//end class
