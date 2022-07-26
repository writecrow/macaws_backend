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
   * Helper function.
   *
   * @param string[] $matching_texts
   *   An array of entity data, including metadata.
   * @param string[] $tokens
   *   The words/phrases to be highlighted.
   */
  public static function getExcerptOrFullText(array $matching_texts, array $tokens, $facet_map, $limit = 20, $offset = 0, $do_excerpt = TRUE, $excerpt_type = "concat") {
    if (empty($matching_texts)) {
      return [];
    }
    $connection = \Drupal::database();
    $query = $connection->select('node__field_text', 'n')
      ->fields('n', ['entity_id', 'field_text_value'])
      ->condition('n.entity_id', array_keys($matching_texts), 'IN');
    $query->range($offset, $limit);
    $results = $query->execute()->fetchAllKeyed();
    $sliced_matches = array_intersect_key($matching_texts, $results);
    $metadata_names = [
      'assignment_topic',
      'assignment_mode',
      'course',
      'draft',
      'course_semester',
      'course_year',
    ];
    foreach ($sliced_matches as $id => $metadata) {
      $excerpts[$id]['filename'] = $metadata['filename'];
      $excerpts[$id]['wordcount'] = $metadata['wordcount'];
      foreach ($metadata_names as $name) {
        $excerpts[$id][$name] = self::getFacetName($metadata[$name], $name, $facet_map);
      }
      if ($do_excerpt) {
        $excerpts[$id]['text'] = HighlightExcerpt::highlight($results[$id], $tokens, $length = "300", $excerpt_type);
      }
      else {
        $excerpts[$id]['text'] = $results[$id];
      }
    }
    return array_values($excerpts);
  }

  /**
   * Simple facet name array lookup.
   */
  public static function getFacetName($ids, $facet_group, $facet_map) {
    $labels = [];
    foreach (array_keys($ids) as $id) {
      if (!empty($facet_map['by_id'][$facet_group][$id])) {
        $labels[] = $facet_map['by_id'][$facet_group][$id];
      }
    }
    return implode(', ', $labels);
  }

}
