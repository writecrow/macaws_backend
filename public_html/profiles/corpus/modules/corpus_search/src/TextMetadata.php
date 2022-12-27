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
    'draft' => 'dr',
    'assignment_mode' => 'am',
    'grouped_l1' => 'lo',
  ];

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
    foreach (self::$facetIDs as $field => $alias) {
      $field_name = substr('field_' . $field, 0, 32);
      $query->leftJoin('node__' . $field_name, $alias, 'n.nid = ' . $alias . '.entity_id');
    }
    $query->leftJoin('node__field_wordcount', 'wc', 'n.nid = wc.entity_id');

    $query->fields('n', ['title', 'type', 'nid']);
    foreach (self::$facetIDs as $field => $alias) {
      $field_name = substr('field_' . $field, 0, 32);
      $query->fields($alias, [$field_name . '_target_id']);
    }
    $query->fields('wc', ['field_wordcount_value']);
    $query->condition('n.type', 'text', '=');
    $result = $query->execute();
    $matching_texts = $result->fetchAll();
    $texts = [];
    if (!empty($matching_texts)) {
      foreach ($matching_texts as $result) {
        $texts = self::populateTextMetadata($result, $texts);;
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
    $query->fields('t', ['tid', 'vid', 'name']);
    $result = $query->execute()->fetchAll();
    foreach ($result as $i) {
      $map['by_name'][$i->vid][$i->name] = $i->tid;
      $map['by_id'][$i->vid][$i->tid] = $i->name;
    }
    return $map;
  }

  /**
   * Loop through the facets & increment each item's count.
   */
  public static function countFacets($matching_texts, $facet_map, $conditions) {
    foreach ($matching_texts as $id => $elements) {
      foreach (array_keys(self::$facetIDs) as $f) {
        $ids = array_keys($elements[$f]);
        foreach ($ids as $id) {
          if (isset($facet_map['by_id'][$f][$id])) {
            $name = $facet_map['by_id'][$f][$id];
            if (!isset($facet_results[$f][$name]['count'])) {
              $facet_results[$f][$name]['count'] = 1;
            }
            else {
              $facet_results[$f][$name]['count']++;
            }
          }
        }
      }
    }
    // Add facets that have no matches to the result set.
    // Loop through facet groups (e.g., course, assignment).
    foreach (array_keys(self::$facetIDs) as $f) {
      // Loop through facet names (e.g., ENGL 106, ENGL 107).
      if (isset($facet_map['by_id'][$f])) {
        foreach ($facet_map['by_id'][$f] as $n) {
          if (!isset($facet_results[$f][$n])) {
            $facet_results[$f][$n]['count'] = 0;
          }
          $facet_id = $facet_map['by_name'][$f][$n];
          if (isset($conditions[$f])) {
            if (in_array($facet_id, $conditions[$f])) {
              $facet_results[$f][$n]['active'] = TRUE;
            }
          }
        }
        // Ensure facets are listed alphanumerically.
        ksort($facet_results[$f]);
        // Move "Other" to end of results.
        if (isset($facet_results[$f]['Other'])) {
          $temp = $facet_results[$f]['Other'];
          unset($facet_results[$f]['Other']);
          $facet_results[$f]['Other'] = $temp;
        }
      }
    }
    return $facet_results;
  }

  /**
   * Helper function to put a single text's result data into a structured array.
   */
  private static function populateTextMetadata($result, $texts) {
    if (!isset($texts[$result->nid])) {
      $texts[$result->nid] = ['filename' => $result->title];
      $texts[$result->nid]['wordcount'] = $result->field_wordcount_value;
    }
    $texts[$result->nid]['macro_genre'][$result->field_macro_genre_target_id] = 1;
    $texts[$result->nid]['draft'][$result->field_draft_target_id] = 1;
    $texts[$result->nid]['target_language'][$result->field_target_language_target_id] = 1;
    $texts[$result->nid]['course_year'][$result->field_course_year_target_id] = 1;
    $texts[$result->nid]['course_semester'][$result->field_course_semester_target_id] = 1;
    $texts[$result->nid]['course'][$result->field_course_target_id] = 1;
    $texts[$result->nid]['grouped_l1'][$result->field_grouped_l1_target_id] = 1;
    $texts[$result->nid]['assignment_topic'][$result->field_assignment_topic_target_id] = 1;
    $texts[$result->nid]['assignment_mode'][$result->field_assignment_mode_target_id] = 1;
    $texts[$result->nid]['assignment_name'][$result->field_assignment_name_target_id] = 1;
    return $texts;
  }

}
