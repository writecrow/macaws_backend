<?php

/**
 * @file
 * Drush commands for the pat_redirect_import module.
 */

use Drupal\corpus_importer\ImporterService;

/**
 * Implements hook_drush_commands().
 */
function corpus_importer_drush_command() {
  $items = array();

  $items['corpus-import'] = array(
    'description' => "Import corpus tagged files",
    'arguments' => array(
      'path' => 'Path to the folder that includes the files',
    ),
    'examples' => [
      'drush corpus-import 2019_data',
    ],
    'aliases' => array('corpus-i'),
  );
  $items['corpus-wipe'] = [
    'description' => "Delete all corpus/repository nodes",
    'examples' => [
      'drush corpus-wipe',
    ],
    'aliases' => array('corpus-wipe'),
  ];

  return $items;
}

/**
 * Implements the drush command.
 */
function drush_corpus_importer_corpus_import($path) {
  if (!file_exists($path)) {
    drush_log("Path $file doesn't exist", 'error');
    exit;
  }
  ImporterService::import($path, array('lorem' => FALSE));
}

/**
 * Implements the drush command.
 */
function drush_corpus_importer_corpus_wipe() {
  print_r("Finding existing nodes..." . PHP_EOL);
  ini_set("memory_limit", "4096M");
  $corpus_deleted = FALSE;
  $result = \Drupal::entityQuery('node')
    ->condition('type', 'text')
    ->execute();
  print_r(count($result) . " corpus nodes exist in the database..." . PHP_EOL);
  while ($corpus_deleted !== TRUE) {
     $result = \Drupal::entityQuery('node')
       ->condition('type', 'text')
       ->range(0, 20)
       ->execute();
     if (count($result) !== 0) {
       print_r(count($result) . " corpus nodes deleted..." . PHP_EOL);
       entity_delete_multiple('node', $result);
     }
     else {
       $corpus_deleted = TRUE;
     }
  }

  $result = \Drupal::entityQuery('node')
    ->condition('type', 'resource')
    ->range(0, 500)
    ->execute();
  print_r(count($result) . " repository nodes will be deleted...");
  entity_delete_multiple('node', $result);
}
