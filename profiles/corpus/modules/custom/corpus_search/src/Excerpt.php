<?php

namespace Drupal\corpus_search;

use writecrow\Highlighter\HighlightExcerpt;

/**
 * Class Excerpt.
 *
 * @package Drupal\corpus_search
 */
class Excerpt {

  /**
   * Main function.
   *
   * @param string[] $matching_texts
   *   An array of entity data, including metadata.
   * @param string[] $tokens
   *   The words/phrases to be highlighted.
   */
  public static function getExcerpts(array $matching_texts, array $tokens, $facet_map, $limit = 20) {
    if (empty($matching_texts)) {
      return [];
    }
    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'n')
      ->fields('n', ['entity_id', 'field_body_value'])
      ->condition('n.entity_id', array_keys($matching_texts), 'IN');
    $query->range(0, $limit);
    $results = $query->execute()->fetchAllKeyed();
    $sliced_matches = array_intersect_key($matching_texts, $results);
    foreach ($sliced_matches as $id => $metadata) {
      $excerpts[] = [
        'excerpt' => HighlightExcerpt::highlight($results[$id], $tokens),
        'filename' => $metadata['filename'],
        'assignment' => self::getFacetName($metadata['assignment'], 'assignment', $facet_map),
        'course' => self::getFacetName($metadata['course'], 'course', $facet_map),
        'draft' => self::getFacetName($metadata['draft'], 'draft', $facet_map),
        'gender' => self::getFacetName($metadata['gender'], 'gender', $facet_map),
        'semester' => self::getFacetName($metadata['semester'], 'semester', $facet_map),
        'toefl_total' => $metadata['toefl_total'],
        'year' => self::getFacetName($metadata['year'], 'year', $facet_map),
      ];
    }

    return $excerpts;
  }

  /**
   * Return text with metadata (used for CSV export).
   */
  public static function getFullText(array $matching_texts, array $tokens, $facet_map, $limit = 20) {
    $texts = [];
    if (empty($matching_texts)) {
      return [];
    }
    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'n')
      ->fields('n', ['entity_id', 'field_body_value'])
      ->condition('n.entity_id', array_keys($matching_texts), 'IN');
    $query->range(0, $limit);
    $results = $query->execute()->fetchAllKeyed();
    $sliced_matches = array_intersect_key($matching_texts, $results);
    foreach ($sliced_matches as $id => $metadata) {
      $texts[] = [
        'filename' => $metadata['filename'],
        'assignment' => self::getFacetName($metadata['assignment'], 'assignment', $facet_map),
        'course' => self::getFacetName($metadata['course'], 'course', $facet_map),
        'draft' => self::getFacetName($metadata['draft'], 'draft', $facet_map),
        'gender' => self::getFacetName($metadata['gender'], 'gender', $facet_map),
        'semester' => self::getFacetName($metadata['semester'], 'semester', $facet_map),
        'toefl_total' => $metadata['toefl_total'],
        'year' => self::getFacetName($metadata['year'], 'year', $facet_map),
        'text' => $results[$id],
      ];
    }
    return $texts;
  }

  /**
   * Simple facet name array lookup.
   */
  public static function getFacetName($id, $facet_group, $facet_map) {
    if (!empty($facet_map['by_id'][$facet_group][$id])) {
      return $facet_map['by_id'][$facet_group][$id];
    }
    return $id;
  }

}
