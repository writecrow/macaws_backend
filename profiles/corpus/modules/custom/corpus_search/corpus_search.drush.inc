<?php

/**
 * @file
 * Drush commands for the corpus_search module.
 */

use Drupal\corpus_search\CorpusWordFrequency;

/**
 * Implements hook_drush_commands().
 */
function corpus_search_drush_command() {
  $items = [];
  $items['corpus-word-count'] = [
    'description' => "Count words",
    'aliases' => array('cwc'),
  ];
  $items['corpus-word-wipe'] = [
    'description' => "Clear the frequency analysis data",
    'aliases' => array('cww'),
  ];
  return $items;
}

/**
 * Implements the drush command.
 */
function drush_corpus_search_corpus_word_count() {
  CorpusWordFrequency::analyze();
}

/**
 * Implements the drush command.
 */
function drush_corpus_search_corpus_word_wipe() {
  CorpusWordFrequency::wipe();
  print_r('Frequency data reset. Run drush cwc to re-run.' . PHP_EOL);
}
