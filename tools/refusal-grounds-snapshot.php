<?php

/**
 * Write, or check, the release-time snapshot of the Woo refusal grounds.
 *
 *     php tools/refusal-grounds-snapshot.php           (composer snapshot:refusal-grounds)
 *     php tools/refusal-grounds-snapshot.php --check   (composer check:refusal-grounds-snapshot)
 *
 * The snapshot is the seeded active list in the shape of
 * WooRefusalGrounds::list(), for filinq and opencatalogi to vendor as the
 * fallback when dossiq is absent (woo-refusal-grounds-list, REQ-WRG-008, D-4).
 * The check fails and names every ground that differs from the seed.
 *
 * @category Tools
 * @package  OCA\Dossiq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$fragmentPath = $root . '/lib/Settings/register.d/84-woo-refusal-grounds.json';
$snapshotPath = $root . '/lib/Settings/woo-refusal-grounds.snapshot.json';
$keys = ['id', 'code', 'article', 'paragraph', 'letter', 'label', 'description', 'parent', 'status', 'legalSource', 'kind', 'citable'];

$fragment = json_decode((string)file_get_contents($fragmentPath), true);
$grounds = [];
foreach ($fragment['components']['objects'] as $object) {
	if (($object['status'] ?? 'active') !== 'active') {
		continue;
	}

	$ground = [];
	foreach ($keys as $key) {
		$ground[$key] = ($object[$key] ?? null);
	}

	// The seed has no uuid yet; the slug is the stable id a consumer can key on.
	$ground['id'] = $object['@self']['slug'];
	$grounds[] = $ground;
}

usort($grounds, static fn (array $left, array $right): int => strnatcmp($left['code'], $right['code']));
$version = (string)$fragment['components']['schemas']['wooRefusalGround']['version'];

if (in_array('--check', $argv, true) === true) {
	$snapshot = json_decode((string)@file_get_contents($snapshotPath), true);
	if (is_array($snapshot) === false) {
		fwrite(STDERR, "The refusal grounds snapshot is missing. Run composer snapshot:refusal-grounds.\n");
		exit(1);
	}

	$stored = [];
	foreach (($snapshot['grounds'] ?? []) as $ground) {
		$stored[(string)($ground['code'] ?? '')] = $ground;
	}

	$differ = [];
	foreach ($grounds as $ground) {
		if (($stored[$ground['code']] ?? null) !== $ground) {
			$differ[] = $ground['code'];
		}

		unset($stored[$ground['code']]);
	}

	$differ = array_merge($differ, array_keys($stored));
	if ($differ !== [] || ($snapshot['version'] ?? '') !== $version) {
		fwrite(
			STDERR,
			'The refusal grounds snapshot differs from the seed for: ' . implode(', ', ($differ === [] ? ['version'] : $differ))
			. ". Run composer snapshot:refusal-grounds.\n"
		);
		exit(1);
	}

	echo 'The refusal grounds snapshot matches the seed (' . count($grounds) . " grounds).\n";
	exit(0);
}

$snapshot = [
	'version' => $version,
	'generatedAt' => gmdate('Y-m-d'),
	'source' => 'dossiq',
	'grounds' => $grounds,
];
file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo 'wrote ' . count($grounds) . " grounds to lib/Settings/woo-refusal-grounds.snapshot.json\n";
