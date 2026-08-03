<?php
/**
 * CI bootstrap for the Credoq suite's cross-plugin harness tests.
 *
 * Set CREDOQ_PLUGINS_DIR as an environment variable pointing at a folder
 * containing all five plugin checkouts side by side, using their real
 * folder names:
 *   plugins/
 *     credoq-engine-v3/
 *     credoq-appointments/
 *     credoq-events-v3/
 *     credoq-seats/
 *     credoq-membership-v3/
 *
 * Falls back to ../plugins relative to this file if unset, so `git clone`
 * or CI checkout can just drop plugin folders in a sibling `plugins/` dir.
 */
define('PLUGINS_DIR', rtrim(getenv('CREDOQ_PLUGINS_DIR') ?: (__DIR__ . '/../plugins'), '/'));
