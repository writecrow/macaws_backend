<?php

namespace Drupal\corpus_search;

use Drupal\corpus_search\Controller\CorpusSearch;

/**
 * Class SearchService.
 *
 * @package Drupal\corpus_search
 */
class SearchService {

  /**
   * Retrieve matching results from word_frequency table.
   */
  public static function simpleSearch($word, $case = 'insensitive') {
    // Create an object of type Select and directly
    // add extra detail to this query object: a condition, fields and a range.
    $connection = \Drupal::database();
    $query = $connection->select('word_frequency', 'f')->fields('f', ['count', 'ids']);
    $query->condition('word', db_like($word), 'LIKE BINARY');
    $result = $query->execute();
    $counts = $result->fetchAssoc();
    if (!$counts['count']) {
      $counts['count'] = 0;
    }
    $counts['raw'] = $counts['count'];
    if ($case == 'insensitive') {
      $query = $connection->select('word_frequency', 'f')->fields('f', ['count', 'ids']);
      if (ctype_lower($word)) {
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
    if (!$counts['ids']) {
      $ids = [];
    }
    else {
      $ids = explode(',', $counts['ids']);
    }
    unset($counts['count']);
    $counts['texts'] = count(array_unique($ids));
    $counts['ids'] = $ids;
    return $counts;
  }

  /**
   * Count texts containing a given list of words.
   */
  public static function countTextsContaining($words) {
    $connection = \Drupal::database();
    $all_ids = [];
    foreach ($words as $word => $type) {
      $ids = [];
      $query = $connection->select('word_frequency', 'f')->fields('f', ['ids']);
      $query->condition('word', db_like($word), 'LIKE BINARY');
      $result = $query->execute();
      $id_string = $result->fetchField();
      if (!empty($id_string)) {
        $ids = explode(',', $id_string);
      }
      // Append case-insensitive results.
      if ($type == 'standard') {
        $query = $connection->select('word_frequency', 'f')->fields('f', ['ids']);
        if (ctype_lower($word)) {
          $query->condition('word', db_like(ucfirst($word)), 'LIKE BINARY');
        }
        else {
          $query->condition('word', db_like(strtolower($word)), 'LIKE BINARY');
        }
        $result = $query->execute();
        $id_string = $result->fetchField();
        if (!empty($id_string)) {
          $insensitive_ids = explode(',', $id_string);
        }
        $ids = array_unique(array_merge($insensitive_ids, $ids));
      }
      if (!empty($ids)) {
        $all_ids = array_unique(array_merge($ids, $all_ids));
      }
    }
    return count($all_ids);
  }

  /**
   * Query for texts, without any text search conditions.
   */
  public static function nonTextSearch($conditions) {
    // Create an object of type Select and directly
    // add extra detail to this query object: a condition, fields and a range.
    $connection = \Drupal::database();
    $query = $connection->select('node_field_data', 'n');

    $query->leftJoin('node__field_body', 'f', 'n.nid = f.entity_id');
    $query->leftJoin('node__field_assignment', 'at', 'n.nid = at.entity_id');
    $query->leftJoin('node__field_college', 'co', 'n.nid = co.entity_id');
    $query->leftJoin('node__field_country', 'cy', 'n.nid = cy.entity_id');
    $query->leftJoin('node__field_course', 'ce', 'n.nid = ce.entity_id');
    $query->leftJoin('node__field_draft', 'dr', 'n.nid = dr.entity_id');
    $query->leftJoin('node__field_gender', 'ge', 'n.nid = ge.entity_id');
    $query->leftJoin('node__field_institution', 'it', 'n.nid = it.entity_id');
    $query->leftJoin('node__field_program', 'pr', 'n.nid = pr.entity_id');
    $query->leftJoin('node__field_semester', 'se', 'n.nid = se.entity_id');
    $query->leftJoin('node__field_toefl_total', 'tt', 'n.nid = tt.entity_id');
    $query->leftJoin('node__field_year_in_school', 'ys', 'n.nid = ys.entity_id');
    $query->leftJoin('node__field_year', 'yr', 'n.nid = yr.entity_id');
    $query->leftJoin('node__field_wordcount', 'wc', 'n.nid = wc.entity_id');

    $query->fields('n', ['title', 'type', 'nid']);
    $query->fields('f', ['field_body_value']);
    $query->fields('at', ['field_assignment_target_id']);
    $query->fields('co', ['field_college_target_id']);
    $query->fields('cy', ['field_country_target_id']);
    $query->fields('ce', ['field_course_target_id']);
    $query->fields('dr', ['field_draft_target_id']);
    $query->fields('ge', ['field_gender_target_id']);
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
          $excerpts[$result->title]['excerpt'] = substr($text, 0, 250);
          $excerpts[$result->title]['assignment'] = $result->field_assignment_target_id;
          $excerpts[$result->title]['institution'] = $result->field_institution_target_id;
          $excerpts[$result->title]['draft'] = $result->field_draft_target_id;
          $excerpts[$result->title]['toefl_total'] = $result->field_toefl_total_value;
          $excerpts[$result->title]['gender'] = $result->field_gender_target_id;
          $excerpts[$result->title]['semester'] = $result->field_semester_target_id;
          $excerpts[$result->title]['year'] = $result->field_year_target_id;
          $excerpts[$result->title]['course'] = $result->field_course_target_id;
        }
        $instance_count++;
        $text_data[$result->nid] = [
          'assignment' => $result->field_assignment_target_id,
          'college' => $result->field_college_target_id,
          'country' => $result->field_country_target_id,
          'course' => $result->field_course_target_id,
          'draft' => $result->field_draft_target_id,
          'gender' => $result->field_gender_target_id,
          'institution' => $result->field_institution_target_id,
          'program' => $result->field_program_target_id,
          'semester' => $result->field_semester_target_id,
          'year' => $result->field_year_target_id,
          'year_in_school' => $result->field_year_in_school_target_id,
          'wordcount' => $result->field_wordcount_value,
        ];
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
          $excerpts[$result->title]['excerpt'] = self::getExcerpt($text, $phrase);
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
        $text_data[$result->entity_id] = [
          'assignment' => $result->field_assignment_target_id,
          'college' => $result->field_college_target_id,
          'country' => $result->field_country_target_id,
          'course' => $result->field_course_target_id,
          'draft' => $result->field_draft_target_id,
          'gender' => $result->field_gender_target_id,
          'institution' => $result->field_institution_target_id,
          'program' => $result->field_program_target_id,
          'semester' => $result->field_semester_target_id,
          'year' => $result->field_year_target_id,
          'year_in_school' => $result->field_year_in_school_target_id,
          'wordcount' => $result->field_wordcount_value,
        ];
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
    foreach (CorpusSearch::$facetIDs as $name => $abbr) {
      if (isset($conditions[$name])) {
        $query->condition($abbr . '.field_' . $name . '_target_id', $conditions[$name], 'IN');
      }
    }
    return $query;
  }

  /**
   * Helper function to return a substring.
   */
  public static function getExcerpt($text, $token) {
    $pos = strpos($text, $token);
    $start = $pos - 100 < 0 ? 0 : $pos - 100;
    $excerpt = substr($text, $start, 250);
    // Boldface match.
    return str_replace($token, '<mark>' . $token . '</mark>', $excerpt);
  }

  /**
   * Retrieve which entities should be counted.
   *
   * @return int[]
   *   IDs of texts
   */
  protected static function retrieveCorpusTextIds() {
    $nids = \Drupal::entityQuery('node')->condition('type', 'text')->execute();
    if (!empty($nids)) {
      return(array_values($nids));
    }
    return FALSE;
  }

  /**
   * Split on punctuation boundary.
   */
  public static function tokenize($string) {
    $tokens = preg_split("/\s|[,.!?;\"”]/", $string);
    $result = [];
    $strip_chars = ":,.!&\?;\”'()";
    foreach ($tokens as $token) {
      $token = trim($token, $strip_chars);
      if ($token) {
        $result[] = $token;
      }
    }
    return $result;
  }

}
