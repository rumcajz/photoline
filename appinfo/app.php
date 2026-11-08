<?php
/**
 * YearRoll
 *
 * @license AGPL-3.0
 */

$app = new \OCA\PhotosTimeline\AppInfo\Application();
$app->ensureBackgroundJob();
$app->registerFilesIntegration();
$app->registerUserCleanup();
