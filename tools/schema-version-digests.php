<?php

/**
 * Regenerate the schema version digests the drift test ratchets against.
 *
 * Run it after deliberately changing a schema AND moving its `version`:
 *
 *     php tools/schema-version-digests.php
 *
 * It is a generator and never a fixer. Running it without bumping the version
 * would record the new content against the old number and switch the gate off
 * for that schema, which is the whole failure it exists to catch. The test
 * says so in its own message, and this file says so here.
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

require_once __DIR__ . '/../tests/Support/SchemaVersionDigest.php';

use OCA\Dossiq\Tests\Support\SchemaVersionDigest;

$root = dirname(__DIR__);
$rows = SchemaVersionDigest::collect(root: $root);

file_put_contents(
	$root . '/' . SchemaVersionDigest::DIGEST_FILE,
	json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n"
);

printf("wrote %d schema digests to %s\n", count($rows), SchemaVersionDigest::DIGEST_FILE);
