<?php

namespace Drupal\corpus_search\Controller;

use Drupal\corpus_search\SearchService as Search;
use Drupal\corpus_search\CorpusWordFrequency as Frequency;
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

  public static $facetIDs = [
    'assignment' => 'at',
    'college' => 'co',
    'country' => 'cy',
    'course' => 'ce',
    'draft' => 'dr',
    'gender' => 'ge',
    'institution' => 'it',
    'program' => 'pr',
    'semester' => 'se',
    'year' => 'yr',
    'year_in_school' => 'ys',
  ];

  /**
   * Given a search string in query parameters, return full results.
   */
  public function search(Request $request) {
    $facet_map = self::getFacetMap();
    $global = [
      'instance_count' => 0,
      'text_ids' => [],
      'subcorpus_wordcount' => 0,
      'facet_counts' => [],
    ];
    $token_data = [];

    $search_string = $request->query->get('search');
    if ($search_string) {
      $tokens = self::getTokens($search_string);
      $results['tokens'] = $tokens;
    }

    $conditions = self::getConditions($request->query->all(), $facet_map);
    // Initiate a search.
    if (!$tokens) {
      // Perform a non-text string search.
      $data = Search::nonTextSearch($conditions);
      $token_data[] = $data;
      $global = self::updateGlobalData($global, $data);
    }
    else {
      foreach ($tokens as $token => $type) {
        $data = self::getIndividualSearchResults($token, $type, $conditions);
        $token_data[$token] = $data;
        $global = self::updateGlobalData($global, $data);
      }
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
    $results['pager']['subcorpus_wordcount'] = $global['subcorpus_wordcount'];

    // Get facet counts.
    $processed_texts = [];
    foreach ($token_data as $t) {
      foreach ($t['text_data'] as $id => $elements) {
        if (!in_array($id, $processed_texts)) {
          foreach (array_keys(self::$facetIDs) as $f) {
            if (isset($facet_map['by_id'][$f]{$elements[$f]})) {
              $name = $facet_map['by_id'][$f]{$elements[$f]};
              if (!isset($facet_results[$f][$name]['count'])) {
                $facet_results[$f][$name]['count'] = 0;
              }
              else {
                $facet_results[$f][$name]['count']++;
              }
            }
          }
          // Ensure this text is not counted multiple times.
          $processed_texts[] = $id;
        }
      }
    }
    // Add facets that have no matches to the result set.
    // Loop through facet groups (e.g., course, assignment).
    foreach (array_keys(self::$facetIDs) as $f) {
      // Loop through facet names (e.g., ENGL 106, ENGL 107).
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
    }

    $results['facets'] = $facet_results;
    // Get search excerpts.
    $excerpts = [];
    // Handle 1 & multiple search terms differently.
    if (count($token_data) > 1) {
      $filenames = [];
      foreach ($token_data as $t) {
        if (isset($t['excerpts'])) {
          foreach ($t['excerpts'] as $filename => $excerpt_data) {
            if (!in_array($filename, $existing_filenames)) {
              $excerpts[] = self::prepareExcerptMetadata($excerpt_data, $facet_map);
              $filenames[] = $filename;
            }
          }
          $results['search_results'] = $results['search_results'] + $excerpts;
        }
      }
    }
    else {
      foreach ($token_data as $t) {
        foreach ($t['excerpts'] as $filename => $excerpt_data) {
          $excerpts[] = self::prepareExcerptMetadata($excerpt_data, $facet_map);
        }
        $results['search_results'] = $results['search_results'] + $excerpts;
      }
    }

    // Final stage! Get frequency data!
    // Loop through tokens once more, now that we know the subcorpus wordcount.
    if ($tokens) {
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
    }

    // Response.
    // $response = new CacheableJsonResponse([], 200);
    $response = new JsonResponse([], 200);
    $response->setContent(json_encode($results));
    $response->headers->set('Content-Type', 'application/json');
    // $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }

  /**
   * Add metadata fields to $excerpt_data array.
   */
  private static function prepareExcerptMetadata($excerpt_data, $facet_map) {
    foreach (array_keys(self::$facetIDs) as $facet_group) {
      if (isset($excerpt_data[$facet_group])) {
        $id = $excerpt_data[$facet_group];
        if (!empty($facet_map['by_id'][$facet_group][$id])) {
          $name = $facet_map['by_id'][$facet_group][$id];
          $excerpt_data[$facet_group] = $name;
        }
      }
    }
    return $excerpt_data;
  }

  /**
   * Calculate unique texts && subcorpus wordcount.
   */
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
   * Parse the query for user-supplied search parameters.
   */
  protected static function getConditions($parameters, $facet_map) {
    $conditions = [];
    foreach (array_keys(self::$facetIDs) as $id) {
      if (isset($parameters[$id])) {
        $param_string = explode("+", $parameters[$id]);
        foreach ($param_string as $param) {
          if (!empty($facet_map['by_name'][$id][$param])) {
            $conditions[$id][] = $facet_map['by_name'][$id][$param];
          }
        }
      }
    }

    return $conditions;
  }

  /**
   * Determine which type of search to perform.
   */
  protected static function getTokens($search_string) {
    $result = [];
    $tokens = preg_split("/\"[^\"]*\"(*SKIP)(*F)|[ \/]+/", $search_string);
    if (!empty($tokens)) {
      // Determine whether to do a phrase or word search & case-sensitivity.
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
          $tokenized = Frequency::tokenize($token);
          $token = $tokenized[0];
          $result[strtolower($token)] = 'word';
        }
      }
    }
    return $result;
  }

  /**
   * Helper method to direct the type of search to the search method.
   */
  protected static function getIndividualSearchResults($token, $type, $conditions) {
    $data = [];
    switch ($type) {
      case 'phrase':
        $length = strlen($token);
        // Remove quotation marks.
        $cleaned = substr($token, 1, $length - 2);
        $data = Search::phraseSearch($cleaned, $conditions);
        break;

      case 'quoted-word':
        $length = strlen($token);
        $cleaned = substr($token, 1, $length - 2);
        $data = Search::simpleSearch($cleaned, $conditions, 'sensitive');
        break;

      case 'word':
        $data = Search::simpleSearch($token, $conditions);
        break;
    }
    return $data;
  }

}
