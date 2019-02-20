<?php

namespace Drupal\corpus_search;

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
    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'n')
      ->fields('n', ['entity_id', 'field_body_value'])
      ->condition('n.entity_id', array_keys($matching_texts), 'IN');
    $query->range(0, $limit);
    $results = $query->execute()->fetchAllKeyed();
    $sliced_matches = array_intersect_key($matching_texts, $results);
    foreach ($sliced_matches as $id => $metadata) {
      $excerpts[] = [
        'excerpt' => self::highlightExcerpt($results[$id], $tokens),
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
   * Simple facet name array lookup.
   */
  public static function getFacetName($id, $facet_group, $facet_map) {
    if (!empty($facet_map['by_id'][$facet_group][$id])) {
      return $facet_map['by_id'][$facet_group][$id];
    }
    return $id;
  }

  /**
   * How shall we highlight this thing?
   */
  public static function highlightExcerpt($text, $tokens) {
    return substr(strip_tags($text), 0, 250);
  }

  /**
   * Helper function to return a highlighted string.
   */
  public static function getExcerptFancy($text, $tokens, $case = "insensitive", $method = "word") {
    $text = strip_tags($text);
    if ($method == "lemma") {
      foreach ($tokens as $lemma) {
        preg_match('/[^a-zA-Z>]' . $lemma . '[^a-zA-Z<]/i', $text, $match);
        if (isset($match[0])) {
          $first_char = substr($match[0], 0, 1);
          $last_char = substr($match[0], -1);
          $pos = stripos($text, $match[0]);
          if ($pos > 0) {
            $start = $pos - 50 < 0 ? 0 : $pos - 50;
            $excerpt = substr($text, $start, 125);
            $replacement = $first_char . '<mark>' . strtolower($lemma) . '</mark>' . $last_char;
            $excerpt = preg_replace('/[^a-zA-Z>]' . strtolower($lemma) . '[^a-zA-Z<]/', $replacement, $excerpt);
            $replacement = $first_char . '<mark>' . ucfirst($lemma) . '</mark>' . $last_char;
            $excerpt = preg_replace('/[^a-zA-Z>]' . ucfirst($lemma) . '[^a-zA-Z<]/', $replacement, $excerpt);
            $word_boundary = substr($excerpt, strpos($excerpt, ' '), strrpos($excerpt, ' '));
            $word_boundary = substr($excerpt, strpos($excerpt, ' '), strrpos($excerpt, ' '));
            $excerpt_list[] = $word_boundary;
          }
        }

      }
      return implode('<br />', $excerpt_list);
    }
    $word = $tokens[0];
    // Handle non-lemma search excerpts.
    switch ($case) {
      case "sensitive":
        $pos = strpos($text, $word);
        $start = $pos - 100 < 0 ? 0 : $pos - 100;
        $excerpt = substr($text, $start, 300);
        $word_boundary = substr($excerpt, strpos($excerpt, ' '), strrpos($excerpt, ' '));
        // Boldface match.
        return str_replace($word, '<mark>' . $word . '</mark>', $word_boundary);

      case "insensitive":
        $pos = stripos($text, $word);
        $start = $pos - 100 < 0 ? 0 : $pos - 100;
        $excerpt = substr($text, $start, 300);
        $word_boundary = substr($excerpt, strpos($excerpt, ' '), strrpos($excerpt, ' '));
        // Boldface match.
        $return = str_replace($word, '<mark>' . $tokens[0] . '</mark>', $word_boundary);
        $return = str_replace(strtolower($tokens[0]), '<mark>' . strtolower($tokens[0]) . '</mark>', $return);
        $return = str_replace(ucfirst($tokens[0]), '<mark>' . ucfirst($tokens[0]) . '</mark>', $return);
        return $return;

    }
  }

}
