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
    'examples' => array(
      'drush corpus-import /Users/jmf3658/crow-data',
    ),
    'aliases' => array('corpus-i'),
  );

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
