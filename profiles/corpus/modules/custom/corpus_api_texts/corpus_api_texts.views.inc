<?php

/**
 * @file
 * Register Views field handler for concordance-highlighted excerpt.
 */

/**
 * Implements hook_views_data_alter().
 */
function corpus_api_texts_views_data_alter(array &$data) {
  $data['node']['concordance_views_field'] = array(
    'title' => t('Concordance excerpt'),
    'field' => array(
      'title' => t('Concordance excerpt'),
      'help' => t('Concordance-style content highlighting'),
      'id' => 'concordance_views_field',
    ),
  );
}