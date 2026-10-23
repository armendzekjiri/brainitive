<?php

/*
 * PHPUnit bootstrap. phpunit.xml forces the test database `brainitive_test`; TEST_DB_SUFFIX=<a-z0-9_>
 * switches to `brainitive_test_<suffix>` so several checkouts (worktrees) can run the suite at
 * the same time without sharing one database. The name always starts with brainitive_test.
 */

require __DIR__.'/../vendor/autoload.php';

$suffix = getenv('TEST_DB_SUFFIX');
if (is_string($suffix) && preg_match('/^[a-z0-9_]{1,30}$/', $suffix)) {
    $database = 'brainitive_test_'.$suffix;
    putenv("DB_DATABASE={$database}");
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
}
