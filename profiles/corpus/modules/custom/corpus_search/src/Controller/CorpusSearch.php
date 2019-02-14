<?php

namespace Drupal\corpus_search\Controller;

use Drupal\corpus_search\SearchService as Search;
use Drupal\word_frequency\FrequencyService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * Corpus Search endpoint controller.
 *
 * @package Drupal\corpus_search\Controller
 */
class CorpusSearch extends ControllerBase {

  private static $facet_ids = [
    'assignment',
    'college',
    'country',
    'course',
    'draft',
    'gender',
    'institution',
    'program',
    'semester',
    'year',
    'year_in_school',
  ];

  /**
   * Given a search string in query parameters, return full results.
   */
  public function search(Request $request) {
    $search_string = $request->query->get('search');
    $tokens = self::getTokens($search_string);
    $results['tokens'] = $tokens;
    $facet_map = self::getFacetMap();
    $global = [
      'instance_count' => 0,
      'text_ids' => [],
      'subcorpus_wordcount' => 0,
      'facet_counts' => [],
    ];
    $token_data = [];
    $conditions = self::getConditions($request->query->all(), $facet_map);
    // Get all of the token data for subsequent processing.
    foreach ($tokens as $token => $type) {
      $data = self::getIndividualSearchResults($token, $type, $conditions);
      $global = self::updateGlobalData($global, $data);
      $token_data[$token] = $data;
    }
    // Get the subcorpus normalization ratio (per 1 million words).
    if (!empty($global['subcorpus_wordcount'])) {
      $ratio = 1000000 / $global['subcorpus_wordcount'];
    }

    $results = [
      'search_results' => [],
      'facets' => [],
      'pager' => [],
      'frequency' => [],
    ];

    $results['pager']['total_items'] = count($global['text_ids']);

    // Get facet counts.
    foreach ($token_data as $t) {
      foreach ($t['text_data'] as $id => $elements) {
        if (!in_array($id, $found_ids)) {
          foreach (self::$facet_ids as $f) {
            $name = $facet_map['by_id'][$f]{$elements[$f]};
            $facet_results[$f][$name]['count']++;
          }
          // Ensure that this text is not counted multiple times
          // across multiple tokens searched.
          $found_ids[] = $id;
        }
      }
    }
    // Add facets that have no matches to the result set.
    // Loop through facet groups (e.g., course, assignment).
    foreach (self::$facet_ids as $f) {
      // Loop through facet names (e.g., ENGL 106, ENGL 107).
      foreach ($facet_map['by_id'][$f] as $n) {
        if (!isset($facet_results[$f][$n])) {
          $facet_results[$f][$n]['count'] = 0;
        }
        $facet_id = $facet_map['by_name'][$f][$n];
        if (in_array($facet_id, $conditions[$f])) {
          $facet_results[$f][$n]['active'] = TRUE;
        }
      }
    }
    $results['facets'] = $facet_results;
    // Get search excerpts.
    if (count($token_data) > 1) {
      foreach ($token_data as $t) {
        $results['search_results'] = $results['search_results'] + $t['excerpts'];
      }
    }
    else {
      foreach ($token_data as $t) {
        $results['search_results'] = $results['search_results'] + $t['excerpts'];
      }
    }

    // @testing purposes:
    //unset($results['search_results']);

    // Final stage! Get frequency data!
    // Loop through tokens once more, now the we know the subcorpus wordcount (ratio);
    foreach ($token_data as $t => $individual_data) {
      $results['frequency']['tokens'][$t]['raw'] = $individual_data['instance_count'];
      $results['frequency']['tokens'][$t]['normed'] = $ratio * $individual_data['instance_count'];
      $results['frequency']['tokens'][$t]['texts'] = count($individual_data['text_data']);
    }
    if (count($token_data) > 1) {
      $results['frequency']['totals']['raw'] = $global['instance_count'];
      $results['frequency']['totals']['normed'] = $ratio * $global['instance_count'];
      $results['frequency']['totals']['texts'] = count($global['text_ids']);
    }

    // Response.
    //$response = new CacheableJsonResponse([], 200);
    $response = new JsonResponse([], 200);
    $response->setContent(json_encode($results));
    $response->headers->set('Content-Type', 'application/json');
    //$response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }

  private static function updateGlobalData($global, $individual_search) {
    $global['instance_count'] = $global['instance_count'] + $individual_search['instance_count'];
    foreach ($individual_search['text_data'] as $id => $text_data) {
      // Get an exclusive list of all text ids matching search criteria.
      $global['text_ids'][$id] = 1;
      // Increment subcorpus wordcount.
      $global['subcorpus_wordcount'] = $global['subcorpus_wordcount'] + $text_data['wordcount'];
      // @todo Update individual facet counts.
      // @todo -- for visualizations.

    }
    return $global;
  }

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

  protected static function getConditions($parameters, $facet_map) {
    $conditions = [];
    foreach (self::$facet_ids as $id) {
      if (isset($parameters[$id])) {
        $param_string = explode("|", $parameters[$id]);
        foreach ($param_string as $param) {
          if (!empty($facet_map['by_name'][$id][$param])) {
            $conditions[$id][] = $facet_map['by_name'][$id][$param];
          }
        }
      }
    }

    return $conditions;
  }

  protected static function getTokens($search_string) {
    $result = [];
    $tokens = preg_split("/\"[^\"]*\"(*SKIP)(*F)|[ \/]+/", $search_string);
    if (!empty($tokens)) {
      // Determine whether to do a phrase search or word search & case-sensitivity.
      foreach ($tokens as $token) {
        $length = strlen($token);
        if ((substr($token, 0, 1) == '"') && (substr($token, $length - 1, 1) == '"')) {
          $cleaned = substr($token, 1, $length - 2);
          if (preg_match("/[^a-zA-Z]/", $cleaned)) {
            // This is a quoted string. Do a phrasal search.
            $result[$token] = 'phrase';
          }
          else {
            // This is a case-sensitive word search.
            $result[$token] = 'quoted-word';
          }

        }
        else {
          // This is a word. Remove punctuation.
          $tokenized = Search::tokenize($token);
          $token = $tokenized[0];
          $result[strtolower($token)] = 'word';
        }
      }
    }
    return $result;
  }

  protected static function getIndividualSearchResults($token, $type, $conditions) {
    $data = [];
    switch ($type) {
      case 'phrase':
        $length = strlen($token);
        $cleaned = substr($token, 1, $length - 2);
        $data = Search::phraseSearch($cleaned, $conditions);
        break;

      case 'quoted-word':
        $length = strlen($token);
        $cleaned = substr($token, 1, $length - 2);
        $data = Search::simpleSearch($cleaned, 'sensitive');
        break;

      case 'word':
        $data = Search::simpleSearch($token);
        break;
    }
    return $data;
  }

}
