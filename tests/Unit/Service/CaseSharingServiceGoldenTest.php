<?php

/**
 * CaseSharingService golden test
 *
 * Runs every public method over a set of link, record, consent and store
 * behaviours, and compares every collaborator call (by parameter name),
 * every log line and every answer with a recorded golden file.
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Tests\Support\MakesRecordedCaseShares;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden test for CaseSharingService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\CaseSharingService
 */
class CaseSharingServiceGoldenTest extends TestCase {
	use MakesRecordedCaseShares;
	use MatchesGoldenFiles;

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$token = ['caseId' => 'case-1', 'label' => 'For the lawyer', 'createdBy' => 'alice'];
		$docs = $token + ['sharedDocuments' => ['f-1', 'f-2']];
		$partner = ['caseId' => 'case-1', 'partnerId' => 'org-b', 'permissionLevel' => 'bekijken', 'createdBy' => 'alice'];
		$share = ['id' => 'share-7', 'caseId' => 'case-1', 'shareType' => 'partner', 'status' => 'active'];
		$found = ['find' => ['share-7' => $share]];
		$id = ['shareId' => 'share-7'];
		$revoke = ['shareId' => 'share-7', 'userId' => 'alice'];

		return [
			'token / minimal' => ['createTokenShare', $token, []],
			'token / blank label' => ['createTokenShare', ['label' => ''] + $token, []],
			'token / all options' => ['createTokenShare', $token + ['expiresAt' => '2026-12-31', 'capabilities' => ['read', 'comment'], 'password' => 'pw', 'sharedDocuments' => ['f-1', 2], 'extra' => ['advisoryBody' => 'Commissie']], []],
			'token / link without expiry' => ['createTokenShare', $docs + ['expiresAt' => '2026-12-31'], ['caseLink' => ['id' => 11, 'uuid' => 'link-11', 'url' => 'https://cloud.example/l/abc']]],
			'token / link without url' => ['createTokenShare', $token, ['caseLink' => ['id' => 11]]],
			'token / mint refused' => ['createTokenShare', $docs, ['caseLink' => ['error' => 'No access link service']]],
			'token / one document refused' => ['createTokenShare', $docs, ['fileLinks' => ['f-2' => ['error' => 'No such file']]]],
			'token / bare document link' => ['createTokenShare', $docs, ['fileLinks' => ['f-1' => []]]],
			'token / not recorded' => ['createTokenShare', $docs, ['stored' => ['error' => 'Share schema missing']]],
			'token / not recorded, links left' => ['createTokenShare', $docs, ['stored' => ['error' => 'Share schema missing'], 'notRevoked' => [11]]],
			'partner / minimal' => ['createPartnerShare', $partner, []],
			'partner / message' => ['createPartnerShare', $partner + ['message' => '  hello there  '], []],
			'partner / blank message' => ['createPartnerShare', $partner + ['message' => '   '], []],
			'partner / exclusions' => ['createPartnerShare', $partner + ['fieldExclusions' => ['title', 'title', 'status']], []],
			'partner / unknown fields' => ['createPartnerShare', $partner + ['fieldExclusions' => ['secret', 'title', 'other']], []],
			'partner / numeric field' => ['createPartnerShare', $partner + ['fieldExclusions' => [7]], []],
			'partner / no object service' => ['createPartnerShare', $partner, ['noObjectService' => true]],
			'partner / consent refused' => ['createPartnerShare', $partner + ['fieldExclusions' => ['title']], ['consent' => ['allowed' => false, 'rule' => 'consent-missing', 'sentence' => 'No consent for this partner.']]],
			'partner / consent by uuid' => ['createPartnerShare', $partner, ['consent' => ['allowed' => true, 'rule' => 'r', 'sentence' => '', 'consent' => ['uuid' => 'u-1'], 'scope' => [], 'until' => null]]],
			'partner / no consent record' => ['createPartnerShare', $partner, ['consent' => ['allowed' => true, 'rule' => 'r', 'sentence' => '', 'consent' => null, 'scope' => null, 'until' => null]]],
			'partner / unconfigured' => ['createPartnerShare', $partner, ['config' => ['register' => '', 'case_share_schema' => 'x']]],
			'case id / found' => ['getCaseIdForShare', $id, $found],
			'case id / as array' => ['getCaseIdForShare', $id, $found + ['findAs' => 'array']],
			'case id / numeric' => ['getCaseIdForShare', $id, ['find' => ['share-7' => ['caseId' => 42]]]],
			'case id / without case' => ['getCaseIdForShare', $id, ['find' => ['share-7' => ['id' => 'share-7']]]],
			'case id / not found' => ['getCaseIdForShare', $id, []],
			'case id / find throws' => ['getCaseIdForShare', $id, ['find' => ['share-7' => 'throw']]],
			'case id / no object service' => ['getCaseIdForShare', $id, ['noObjectService' => true]],
			'case id / no register' => ['getCaseIdForShare', $id, $found + ['config' => ['register' => '']]],
			'case id / register zero' => ['getCaseIdForShare', $id, $found + ['config' => ['register' => '0']]],
			'case id / no schema' => ['getCaseIdForShare', $id, $found + ['config' => ['case_share_schema' => '']]],
			'case id / text register' => ['getCaseIdForShare', $id, $found + ['config' => ['register' => 'dossiq']]],
			'revoke / found' => ['revokeShare', $revoke, $found],
			'revoke / as array' => ['revokeShare', $revoke, $found + ['findAs' => 'array']],
			'revoke / links left' => ['revokeShare', $revoke, $found + ['linksRefused' => [11, 12]]],
			'revoke / not found' => ['revokeShare', $revoke, []],
			'revoke / find throws' => ['revokeShare', $revoke, ['find' => ['share-7' => 'throw']]],
			'revoke / no object service' => ['revokeShare', $revoke, ['noObjectService' => true]],
			'revoke / no register' => ['revokeShare', $revoke, $found + ['config' => ['register' => '']]],
			'revoke / no schema' => ['revokeShare', $revoke, $found + ['config' => ['case_share_schema' => '']]],
			'access / allowed' => ['canUserAccessCase', ['caseId' => 'case-1', 'userId' => 'alice'], []],
			'access / refused' => ['canUserAccessCase', ['caseId' => 'case-1', 'userId' => 'mallory'], []],
			'federated / create' => ['createFederatedShare', ['caseId' => 'case-1', 'remoteCloudId' => 'p@remote.example', 'sharedFields' => ['title'], 'sharedDocuments' => [], 'permissionLevel' => 'bekijken', 'createdBy' => 'alice'], []],
			'federated / revoke' => ['revokeFederatedShare', ['shareId' => 'fed-1', 'userId' => 'alice'], []],
			'federated / case id' => ['getCaseIdForFederatedShare', ['shareId' => 'fed-1'], []],
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
			$service = $this->recordedSharingService(behaviour: $behaviour);
			try {
				$answer = $service->$method(...$args);
			} catch (Throwable $e) {
				$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'case-sharing-service', value: $this->withoutClock(value: $actual));
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
		$json = (string)preg_replace('/' . date('Y-m-d') . 'T\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<now>', $json);
		return (array)json_decode($json, true);
	}//end withoutClock()
}//end class
