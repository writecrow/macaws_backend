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
      $query = $connection->select('corpus_lemma_frequency', 'f')->fields('f', ['count', 'ids']);
      $query->condition('word', db_like($lemma), 'LIKE BINARY');
      $result = $query->execute();
      $counts = $result->fetchAssoc();
      if (!$counts['count']) {
        $counts['count'] = 0;
      }
      $counts['raw'] = $counts['count'];
    }
    else {
      $tokens = [$word];
      $connection = \Drupal::database();
      $query = $connection->select('corpus_word_frequency', 'f')->fields('f', ['count', 'ids']);
      $query->condition('word', db_like($word), 'LIKE BINARY');
      $result = $query->execute();
      $counts = $result->fetchAssoc();
      if (!$counts['count']) {
        $counts['count'] = 0;
      }
      $counts['raw'] = $counts['count'];
      if ($case == 'insensitive') {
        $query = $connection->select('corpus_word_frequency', 'f')->fields('f', ['count', 'ids']);
        if (ctype_lower($word[0])) {
          $query->condition('word', db_like(ucfirst($word)), 'LIKE BINARY');
        }
        else {
          $query->condition('word', db_like(strtolower($word)), 'LIKE BINARY');
        }
        $result = $query->execute();
        $item = $result->fetchAssoc();
        $counts['raw'] = $counts['raw'] + $item['count'];
        if ($item['count']) {
          $counts['ids'] = $counts['ids'] . ',' . $item['ids'];
        }
      }
    }

    if (!$counts['ids']) {
      $text_counts = [];
    }
    else {
      // The ids are stored in the format NID:COUNT,NID:COUNT.
      $id_pairs = explode(',', $counts['ids']);
      foreach ($id_pairs as $pair) {
        $nid_and_count = explode(':', $pair);
        $text_counts{$nid_and_count[0]} = $nid_and_count[1];
      }
    }
    // Limit list to intersected NIDs from condition search & token search.
    $intersected_text_ids = array_intersect(array_unique(array_keys($text_counts)), array_values($condition_matches));

    // Get text data for intersected ids.
    $instance_count = 0;
    $text_data = [];
    $excerpts = [];
    if (!empty($intersected_text_ids)) {
      foreach ($intersected_text_ids as $id) {
        // Sum up the instance count across texts.
        $instance_count = $instance_count + $text_counts[$id];
        // Create a temporary array of instance counts to sort by "relevance".
        $text_data[$id] = $text_counts[$id];
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
   * Query for texts by filter condition, excluding text search.
   *
   * This is used by:
   * - self::simpleSearch()
   * - self::nonTextSearch()
   */
  public static function getTextsMatchingConditions($conditions) {
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
    else {
      $connection = \Drupal::database();
      $query = $connection->select('node_field_data', 'n');
      $query->leftJoin('node__field_assignment', 'at', 'n.nid = at.entity_id');
      $query->leftJoin('node__field_college', 'co', 'n.nid = co.entity_id');
      $query->leftJoin('node__field_country', 'cy', 'n.nid = cy.entity_id');
      $query->leftJoin('node__field_course', 'ce', 'n.nid = ce.entity_id');
      $query->leftJoin('node__field_draft', 'dr', 'n.nid = dr.entity_id');
      $query->leftJoin('node__field_gender', 'ge', 'n.nid = ge.entity_id');
      $query->leftJoin('node__field_id', 'id', 'n.nid = id.entity_id');
      $query->leftJoin('node__field_institution', 'it', 'n.nid = it.entity_id');
      $query->leftJoin('node__field_program', 'pr', 'n.nid = pr.entity_id');
      $query->leftJoin('node__field_semester', 'se', 'n.nid = se.entity_id');
      $query->leftJoin('node__field_toefl_total', 'tt', 'n.nid = tt.entity_id');
      $query->leftJoin('node__field_year_in_school', 'ys', 'n.nid = ys.entity_id');
      $query->leftJoin('node__field_year', 'yr', 'n.nid = yr.entity_id');
      $query->leftJoin('node__field_wordcount', 'wc', 'n.nid = wc.entity_id');

      $query->fields('n', ['title', 'type', 'nid']);
      $query->fields('at', ['field_assignment_target_id']);
      $query->fields('co', ['field_college_target_id']);
      $query->fields('cy', ['field_country_target_id']);
      $query->fields('ce', ['field_course_target_id']);
      $query->fields('dr', ['field_draft_target_id']);
      $query->fields('ge', ['field_gender_target_id']);
      $query->fields('id', ['field_id_value']);
      $query->fields('it', ['field_institution_target_id']);
      $query->fields('pr', ['field_program_target_id']);
      $query->fields('se', ['field_semester_target_id']);
      $query->fields('tt', ['field_toefl_total_value']);
      $query->fields('ys', ['field_year_in_school_target_id']);
      $query->fields('yr', ['field_year_target_id']);
      $query->fields('wc', ['field_wordcount_value']);

      $query->condition('n.type', 'text', '=');

      // Apply facet conditions.
      if (!empty($conditions)) {
        $query = self::applyConditions($query, $conditions);
      }

      // Apply other field conditions (TOEFL, ID, etc.).
      // @todo.

      $result = $query->execute();
      $matching_texts = $result->fetchAll();
      $text_data = [];
      if (!empty($matching_texts)) {
        foreach ($matching_texts as $result) {
          $text_data[$result->nid] = self::populateTextMetadata($result);
        }
      }
      \Drupal::cache()->set($cache_id, $text_data, CacheBackendInterface::CACHE_PERMANENT);
      return $text_data;
    }
  }

  /**
   * Helper function to put a single text's result data into a structured array.
   */
  private static function populateTextMetadata($result) {
    return [
      'filename' => $result->title,
      'assignment' => $result->field_assignment_target_id,
      'college' => $result->field_college_target_id,
      'country' => $result->field_country_target_id,
      'course' => $result->field_course_target_id,
      'draft' => $result->field_draft_target_id,
      'gender' => $result->field_gender_target_id,
      'institution' => $result->field_institution_target_id,
      'program' => $result->field_program_target_id,
      'semester' => $result->field_semester_target_id,
      'toefl_total' => $result->field_toefl_total_value,
      'year' => $result->field_year_target_id,
      'year_in_school' => $result->field_year_in_school_target_id,
      'wordcount' => $result->field_wordcount_value,
    ];
  }

  /**
   * Query for texts, without any text search conditions.
   */
  public static function nonTextSearch($conditions) {
    // @todo: consider caching this, too?
    $connection = \Drupal::database();
    $query = $connection->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'type'])
      ->condition('n.type', 'text', '=');
    // Apply facet/filter conditions.
    if (!empty($conditions)) {
      $query = self::applyConditions($query, $conditions);
    }
    $results = $query->execute()->fetchCol();
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
    // Create an object of type Select and directly
    // add extra detail to this query object: a condition, fields and a range.
    $connection = \Drupal::database();
    $query = $connection->select('node__field_body', 'f');

    $query->join('node_field_data', 'n', 'f.entity_id = n.nid');
    $query->leftJoin('node__field_assignment', 'at', 'f.entity_id = at.entity_id');
    $query->leftJoin('node__field_college', 'co', 'f.entity_id = co.entity_id');
    $query->leftJoin('node__field_country', 'cy', 'f.entity_id = cy.entity_id');
    $query->leftJoin('node__field_course', 'ce', 'f.entity_id = ce.entity_id');
    $query->leftJoin('node__field_draft', 'dr', 'f.entity_id = dr.entity_id');
    $query->leftJoin('node__field_gender', 'ge', 'f.entity_id = ge.entity_id');
    $query->leftJoin('node__field_id', 'id', 'f.entity_id = id.entity_id');
    $query->leftJoin('node__field_institution', 'it', 'f.entity_id = it.entity_id');
    $query->leftJoin('node__field_program', 'pr', 'f.entity_id = pr.entity_id');
    $query->leftJoin('node__field_semester', 'se', 'f.entity_id = se.entity_id');
    $query->leftJoin('node__field_toefl_total', 'tt', 'f.entity_id = tt.entity_id');
    $query->leftJoin('node__field_year_in_school', 'ys', 'f.entity_id = ys.entity_id');
    $query->leftJoin('node__field_year', 'yr', 'f.entity_id = yr.entity_id');
    $query->leftJoin('node__field_wordcount', 'wc', 'f.entity_id = wc.entity_id');

    $query->fields('n', ['title', 'type']);
    $query->fields('f', ['entity_id', 'field_body_value']);
    $query->fields('at', ['field_assignment_target_id']);
    $query->fields('co', ['field_college_target_id']);
    $query->fields('cy', ['field_country_target_id']);
    $query->fields('ce', ['field_course_target_id']);
    $query->fields('dr', ['field_draft_target_id']);
    $query->fields('ge', ['field_gender_target_id']);
    $query->fields('id', ['field_id_value']);
    $query->fields('it', ['field_institution_target_id']);
    $query->fields('pr', ['field_program_target_id']);
    $query->fields('se', ['field_semester_target_id']);
    $query->fields('tt', ['field_toefl_total_value']);
    $query->fields('ys', ['field_year_in_school_target_id']);
    $query->fields('yr', ['field_year_target_id']);
    $query->fields('wc', ['field_wordcount_value']);

    $query->condition('n.type', 'text', '=');

    // Apply facet conditions.
    if (!empty($conditions)) {
      $query = self::applyConditions($query, $conditions);
    }

    // Apply other field conditions (TOEFL, ID, etc.).
    // @todo.

    // Apply text conditions.
    $and_condition_1 = $query->orConditionGroup()
      ->condition('field_body_value', "%" . $connection->escapeLike($phrase) . "%", 'LIKE BINARY');
    $result = $query->condition($and_condition_1)->execute();

    $matching_texts = $result->fetchAll();
    $instance_count = 0;
    $text_count = 0;
    $text_data = [];
    $excerpts = [];
    if (!empty($matching_texts)) {
      $inc = 0;
      foreach ($matching_texts as $result) {
        $text = $result->field_body_value;
        if ($inc < 20) {
          $excerpts[$result->title]['filename'] = $result->title;
          $excerpts[$result->title]['excerpt'] = self::getExcerpt($text, [$phrase], 'sensitive');
          $excerpts[$result->title]['assignment'] = $result->field_assignment_target_id;
          $excerpts[$result->title]['institution'] = $result->field_institution_target_id;
          $excerpts[$result->title]['draft'] = $result->field_draft_target_id;
          $excerpts[$result->title]['toefl_total'] = $result->field_toefl_total_value;
          $excerpts[$result->title]['gender'] = $result->field_gender_target_id;
          $excerpts[$result->title]['semester'] = $result->field_semester_target_id;
          $excerpts[$result->title]['year'] = $result->field_year_target_id;
          $excerpts[$result->title]['course'] = $result->field_course_target_id;
        }
        $instance_count = $instance_count + substr_count($text, $phrase);
        $text_data[$result->entity_id] = self::populateTextMetadata($result);
        $inc++;
      }
      $text_count = count($matching_texts);
    }
    return [
      'instance_count' => $instance_count,
      'text_count' => $text_count,
      'text_data' => $text_data,
      'excerpts' => $excerpts,
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
   * Helper function to return a highlighted string.
   */
  public static function getExcerpt($text, $tokens, $case = "insensitive", $method = "word") {
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
