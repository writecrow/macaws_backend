<?php

namespace Drupal\corpus_search;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Class SearchService.
 *
 * @package Drupal\corpus_search
 */
class SearchService {

  /**
   * Retrieve matching results from word_frequency table.
   */
  public static function simpleSearch($word, $conditions, $case = 'insensitive', $method = 'word') {
    // First get the IDs of texts that match the search conditions,
    // irrespective of text search criterion.
    $condition_matches = self::nonTextSearch($conditions);

    // Create an object of type Select and directly
    // add extra detail to this query object: a condition, fields and a range.
    if ($method == 'lemma') {
      $module_handler = \Drupal::service('module_handler');
      $module_path = $module_handler->getModule('search_api_lemma')->getPath();
      // Get lemma stem.
      $lemma = CorpusLemmaFrequency::lemmatize(strtolower($word));
      $tokens = CorpusLemmaFrequency::getVariants($lemma);
      $connection = \Drupal::database();
      $query = $connection->select('corpus_lemma_frequency', 'f')->fields('f', ['ids']);
      $query->condition('word', db_like($lemma), 'LIKE BINARY');
      $result = $query->execute()->fetchAssoc();
      $word_matches = self::arrangeTextCountResults($result['ids']);
    }
    else {
      $tokens = [$word];
      $connection = \Drupal::database();
      $query = $connection->select('corpus_word_frequency', 'f')->fields('f', ['ids']);
      $query->condition('word', db_like($word), 'LIKE BINARY');
      $result = $query->execute()->fetchAssoc();
      $word_matches = self::arrangeTextCountResults($result['ids']);

      if ($case == 'insensitive') {
        $query = $connection->select('corpus_word_frequency', 'f')->fields('f', ['ids']);
        if (ctype_lower($word[0])) {
          $query->condition('word', db_like(ucfirst($word)), 'LIKE BINARY');
        }
        else {
          $query->condition('word', db_like(strtolower($word)), 'LIKE BINARY');
        }
        $result = $query->execute()->fetchAssoc();
        $insensitive = self::arrangeTextCountResults($result['ids']);
        $word_matches = $word_matches + $insensitive;
      }
    }

    // Limit list to intersected NIDs from condition search & token search.
    $intersected_text_ids = array_intersect(array_unique(array_keys($word_matches)), array_values($condition_matches));
    // Get text data for intersected ids.
    $instance_count = 0;
    $text_data = [];
    if (!empty($intersected_text_ids)) {
      foreach ($intersected_text_ids as $id) {
        // Sum up the instance count across texts.
        $instance_count = $instance_count + $word_matches[$id];
        // Create a temporary array of instance counts to sort by "relevance".
        $text_data[$id] = $word_matches[$id];
      }
      arsort($text_data);
    }
    return [
      'instance_count' => $instance_count,
      'text_count' => count($intersected_text_ids),
      'text_ids' => $text_data,
    ];
  }

  /**
   * Query for texts, without any text search conditions.
   */
  public static function nonTextSearch($conditions) {
    if (empty($conditions)) {
      $cache_id = md5('corpus_search_no_conditions');
    }
    else {
      $cachestring = 'corpus_search_conditions_';
      foreach ($conditions as $condition => $values) {
        if (is_array($values)) {
          $criterion = implode('+', $values);
        }
        else {
          $criterion = $values;
        }
        $cachestring .= $condition . "=" . $criterion;
      }
      $cache_id = md5($cachestring);
    }
    if ($cache = \Drupal::cache()->get($cache_id)) {
      return $cache->data;
    }

    $connection = \Drupal::database();
    $query = $connection->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'type'])
      ->condition('n.type', 'text', '=');
    // Apply facet/filter conditions.
    if (!empty($conditions)) {
      $query = self::applyConditions($query, $conditions);
    }
    $results = $query->execute()->fetchCol();
    \Drupal::cache()->set($cache_id, $results, CacheBackendInterface::CACHE_PERMANENT);
    return $results;
  }

  /**
   * Get full text of specified nodes.
   */
  public static function getNodeBodys($nids) {
    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'f');
    $query->condition('f.entity_id', $nids, 'IN');
    $query->fields('f', ['entity_id', 'field_body_value']);
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Query the node__field_body table for exact matches.
   */
  public static function phraseSearch($phrase, $conditions) {
    $condition_matches = self::nonTextSearch($conditions);

    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'f');
    $query->fields('f', ['entity_id', 'field_body_value', 'bundle']);
    $query->condition('f.bundle', 'text', '=');

    // Apply text conditions.
    $and_condition_1 = $query->orConditionGroup()
      ->condition('field_body_value', "%" . $connection->escapeLike($phrase) . "%", 'LIKE BINARY');
    $result = $query->condition($and_condition_1)->execute();

    $phrase_matches = $result->fetchAllKeyed(0, 1);
    $intersected_text_ids = array_intersect(array_keys($phrase_matches), array_values($condition_matches));

    $instance_count = 0;
    $text_data = [];
    if (!empty($intersected_text_ids)) {
      foreach ($intersected_text_ids as $id) {
        $count = substr_count(strip_tags($phrase_matches[$id]), $phrase);
        if ($count != 0) {
          // Sum up the instance count across texts.
          $instance_count = $instance_count + $count;
          // Create a temporary array of instance counts to sort by "relevance".
          $text_data[$id] = $count;
        }
      }
      arsort($text_data);
    }
    return [
      'instance_count' => $instance_count,
      'text_count' => count($text_data),
      'text_ids' => $text_data,
    ];
  }

  /**
   * Helper function to further limit query.
   */
  protected static function applyConditions($query, $conditions) {
    foreach (TextMetadata::$facetIDs as $name => $abbr) {
      if (isset($conditions[$name])) {
        $query->join('node__field_' . $name, $abbr, 'n.nid = ' . $abbr . '.entity_id');
        $query->fields($abbr, ['field_' . $name . '_target_id']);
        $query->condition($abbr . '.field_' . $name . '_target_id', $conditions[$name], 'IN');
      }
    }
    if (isset($conditions['id'])) {
      $query->join('node__field_id', 'id', 'n.nid = id.entity_id');
      $query->fields('id', ['field_id_value']);
      $query->condition('id.field_id_value', $conditions['id'], '=');
    }
    if (isset($conditions['toefl_total_min'])) {
      $query->condition('tt.field_toefl_total_value', (int) $conditions['toefl_total_min'], '>=');
    }
    if (isset($conditions['toefl_total_max'])) {
      $query->condition('tt.field_toefl_total_value', (int) $conditions['toefl_total_max'], '<=');
    }
    return $query;
  }

  /**
   * Helper function to split counts in form NID:COUNT,NID:COUNT.
   */
  public static function arrangeTextCountResults($string) {
    $output = [];
    $comma_separated = explode(',', $string);
    foreach ($comma_separated as $text_and_count) {
      $values = explode(':', $text_and_count);
      $output{$values[0]} = $values[1];
    }
    return $output;
  }

}