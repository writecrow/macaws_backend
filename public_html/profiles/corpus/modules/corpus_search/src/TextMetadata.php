<?php

namespace Drupal\corpus_search;

/**
 * Class SearchService.
 *
 * @package Drupal\corpus_search
 */
class TextMetadata {

  public static $facetIDs = [
    'target_language' => 'tl',
    'course' => 'ce',
    'macro_genre' => 'mg',
    'course_year' => 'cy',
    'course_semester' => 'cs',
    'assignment_topic' => 'ao',
    'assignment_name' => 'an',
    'assignment_mode' => 'am',
    'draft' => 'dr',
    'grouped_l1' => 'lo',
  ];

  public static $corpusSourceBundle = 'text';

  /**
   * Retrieve metadata for all texts in one go!
   */
  public static function getAll() {
    $cache_id = md5('corpus_search_all_texts');
    if ($cache = \Drupal::cache()->get($cache_id)) {
      return $cache->data;
    }
    $connection = \Drupal::database();
    $query = $connection->select('node_field_data', 'n');
    $query->condition('n.type', self::$corpusSourceBundle, '=');
    $query->fields('n', ['title', 'type', 'nid']);
    // Add non-facet fields.
    $query->leftJoin('node__field_wordcount', 'wc', 'n.nid = wc.entity_id');
    $query->fields('wc', ['field_wordcount_value']);
    foreach (self::$facetIDs as $field => $alias) {
      $query->leftJoin('node__field_' . $field, $alias, 'n.nid = ' . $alias . '.entity_id');
      $query->fields($alias, ['field_' . $field . '_target_id']);
    }
    $result = $query->execute();
    $matching_texts = $result->fetchAll();
    $texts = [];
    if (!empty($matching_texts)) {
      foreach ($matching_texts as $result) {
        $texts[$result->nid] = self::populateTextMetadata($result);
      }
    }
    \Drupal::cache()->set($cache_id, $texts, \Drupal::time()->getRequestTime() + (2500000));
    return $texts;
  }

  /**
   * Get map of term name-id relational data.
   */
  public static function getFacetMap() {
    $map = [];
    $connection = \Drupal::database();
    $query = $connection->select('taxonomy_term_field_data', 't');
    $query->condition('t.vid', array_keys(self::$facetIDs), 'IN');
    $query->fields('t', ['tid', 'vid', 'name', 'description__value']);
    $result = $query->execute()->fetchAll();

    $corpus_tids = [];
    foreach (self::$facetIDs as $field => $alias) {
      $query = $connection->select('node__field_' . $field, $alias);
      $query->condition($alias .'.bundle', self::$corpusSourceBundle);
      $query->fields($alias, ['field_' . $field . '_target_id']);
      $corpus_tids[$field] = array_values($query->execute()->fetchCol(0));
    }
    foreach ($result as $i) {
      // Omit any TIDs that are not actively referenced in corpus data.
      if (!in_array($i->tid, $corpus_tids[$i->vid])) {
        continue;
      }
      $data = [
        'name' => $i->name,
      ];
      if (isset($i->description__value)) {
        $data['description'] = strip_tags($i->description__value);
      }
      $map['by_name'][$i->vid][$i->name] = $i->tid;
      $map['by_id'][$i->vid][$i->tid] = $data;
    }
    return $map;
  }

  /**
   * Loop through the facets & increment each item's count.
   */
  public static function countFacets($matching_texts, $facet_map, $conditions) {
    $facet_results = [];
    foreach ($matching_texts as $id => $elements) {
      foreach (array_keys(self::$facetIDs) as $group) {
        if (!isset($elements[$group])) {
          continue;
        }
        if (!isset($facet_map['by_id'][$group][$elements[$group]])) {
          continue;
        }
        $name = $facet_map['by_id'][$group][$elements[$group]]['name'];
        if (!isset($facet_results[$group][$name]['count'])) {
          $facet_results[$group][$name]['count'] = 1;
        }
        else {
          $facet_results[$group][$name]['count']++;
        }
      }
    }
    // Add facets that have no matches to the result set.
    // Loop through facet groups (e.g., course, assignment).
    foreach (array_keys(self::$facetIDs) as $group) {
      // Loop through facet names (e.g., ENGL 106, ENGL 107).
      foreach ($facet_map['by_name'][$group] as $name => $id) {
        if (!isset($facet_results[$group][$name])) {
          // We display items with zero counts so they are still visible.
          $facet_results[$group][$name] = ['count' => 0];
        }
        if (isset($conditions[$group]) && in_array($id, $conditions[$group])) {
          $facet_results[$group][$name]['active'] = TRUE;
        }
        if (isset($facet_results[$group][$name])) {
          // Add description, if it exists..
          if (isset($facet_map['by_id'][$group][$id]['description'])) {
            $facet_results[$group][$name]['description'] = $facet_map['by_id'][$group][$id]['description'];
          }
        }
      }
      // Ensure facets are listed alphanumerically.
      if (isset($facet_results[$group])) {
        ksort($facet_results[$group]);
      }
    }
    return $facet_results;
  }

  /**
   * Helper function to put a single text's result data into a structured array.
   */
  private static function populateTextMetadata($result) {
    $metadata = [
      'filename' => $result->title,
      'wordcount' => $result->field_wordcount_value,
    ];
    foreach (array_keys(self::$facetIDs) as $field) {
      $target = 'field_' . $field . '_target_id';
      if (isset($result->$target)) {
        $metadata[$field] = $result->$target;
      }
    }
    return $metadata;
  }

}
