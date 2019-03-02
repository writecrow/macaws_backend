<?php

namespace Drupal\corpus_search\Controller;

use Drupal\corpus_search\SearchService as Search;
use Drupal\corpus_search\CorpusWordFrequency as Frequency;
use Drupal\corpus_search\TextMetadata;
use Drupal\corpus_search\Excerpt;
use Drupal\corpus_search\CorpusLemmaFrequency;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Utility\Xss;

/**
 * Corpus Search endpoint.
 *
 * @package Drupal\corpus_search\Controller
 */
class CorpusSearch extends ControllerBase {

  /**
   * The Controller endpoint.
   */
  public function endpoint(Request $request) {
    // Response.
    $results = self::search($request);
    $response = new CacheableJsonResponse([], 200);
    // $response = new JsonResponse([], 200); .
    $response->setContent(json_encode($results));
    $response->headers->set('Content-Type', 'application/json');
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }

  /**
   * Given a search string in query parameters, return full results.
   */
  public function search(Request $request) {
    // @todo: limit facet map to just Text matches (& cache?).
    $facet_map = TextMetadata::getFacetMap();
    // Get all facet/filter conditions.
    $conditions = self::getConditions($request->query->all(), $facet_map);
    // Check for presence of cached data.
    $cache_id = self::getCacheString($request);
    if ($cache = \Drupal::cache()->get($cache_id)) {
      return $cache->data;
    }
    $all_texts_metadata = TextMetadata::getAll();
    $ratio = 1;
    $token_data = [];
    $op = 'or';
    $tokens = [];
    $results = [
      'search_results' => [],
      'facets' => [],
      'pager' => [],
      'frequency' => [],
    ];
    $global = [
      'instance_count' => 0,
      'subcorpus_wordcount' => 0,
      'facet_counts' => [],
    ];
    if ($search_string = strip_tags(urldecode($request->query->get('search')))) {
      $tokens = self::getTokens($search_string);
      // Is this and "and" or "or" text search?
      $op = Xss::filter($request->query->get('op'));
      // Retrieve whether a 'lemma' search has been specified.
      $method = Xss::filter($request->query->get('method'));
      foreach ($tokens as $token => $type) {
        $individual_search = self::getIndividualSearchResults($token, $type, $conditions, $method);
        $token_data[$token] = $individual_search;
        $global = self::updateGlobalData($global, $individual_search, $all_texts_metadata, $op);
      }
      $matching_texts = array_intersect_key($all_texts_metadata, $global['text_ids']);
    }
    else {
      // Perform a non-text string search.
      $global['text_ids'] = Search::nonTextSearch($conditions);
      $matching_texts = array_intersect_key($all_texts_metadata, array_flip($global['text_ids']));
    }
    if ($op == 'and' && !empty($token_data)) {
      $updated_token_data = [];
      // Do additional counting operation for AND instance counts.
      foreach ($matching_texts as $id => $placeholder) {
        foreach ($token_data as $token => $data) {
          if (isset($data['text_ids'][$id])) {
            $updated_token_data[$token]['instance_count'] += $data['text_ids'][$id];
            $updated_token_data[$token]['text_count']++;
            $updated_token_data[$token]['text_ids'][$id] = 1;
            $global['instance_count'] += $data['text_ids'][$id];
          }
        }
      }
      $token_data = $updated_token_data;
    }
    foreach ($matching_texts as $t) {
      $global['subcorpus_wordcount'] += $t['wordcount'];
    }

    // Get the subcorpus normalization ratio (per 1 million words).
    if (!empty($global['subcorpus_wordcount'])) {
      $ratio = 1000000 / $global['subcorpus_wordcount'];
    }
    if (!isset($matching_texts)) {
      $matching_texts = [];
    }
    $results['pager']['total_items'] = count($global['text_ids']);
    $results['pager']['subcorpus_wordcount'] = $global['subcorpus_wordcount'];
    $results['facets'] = TextMetadata::countFacets($matching_texts, $facet_map, $conditions);

    $excerpt_tokens = array_keys($tokens);
    // Get frequency data!
    // Loop through tokens once more, now that we know the subcorpus wordcount.
    if (!empty($token_data)) {
      foreach ($token_data as $t => $individual_data) {
        if ($method == 'lemma') {
          $lemma = CorpusLemmaFrequency::lemmatize($t);
          $variants = CorpusLemmaFrequency::getVariants($lemma);
          $excerpt_tokens = $excerpt_tokens + $variants;
          $t = implode('/', $variants);
        }
        $results['frequency']['tokens'][$t]['raw'] = $individual_data['instance_count'];
        $results['frequency']['tokens'][$t]['normed'] = $ratio * $individual_data['instance_count'];
        $results['frequency']['tokens'][$t]['texts'] = count($individual_data['text_ids']);
      }
      if (count($token_data) > 1) {
        $results['frequency']['totals']['raw'] = $global['instance_count'];
        $results['frequency']['totals']['normed'] = $ratio * $global['instance_count'];
        $results['frequency']['totals']['texts'] = count($global['text_ids']);
      }
    }
    // This runs after the frequency data to take advantage of the
    // updated $tokens, if any, from a lemma search.
    $results['search_results'] = Excerpt::getExcerpts($matching_texts, $excerpt_tokens, $facet_map, 20);
    \Drupal::cache()->set($cache_id, $results, CacheBackendInterface::CACHE_PERMANENT);
    return $results;
  }

  /**
   * Calculate unique texts && subcorpus wordcount.
   */
  private static function updateGlobalData($global, $individual_search, $all_texts_metadata, $op = "or") {
    switch ($op) {
      case "and":
        if (!isset($global['text_ids'])) {
          $global['text_ids'] = [];
          // This is the first time through the search.
          // Set the global text IDs to the search results.
          foreach ($individual_search['text_ids'] as $id => $text_data) {
            // Get an exclusive list of all text ids matching search criteria.
            $global['text_ids'][$id] = 1;
          }
        }
        else {
          // Intersect search results.
          $current_global = array_keys($global['text_ids']);
          $global['text_ids'] = [];
          $current_search = array_keys($individual_search['text_ids']);
          $shared_ids = array_intersect($current_global, $current_search);
          foreach (array_values($shared_ids) as $id) {
            $global['text_ids'][$id] = 1;
          }
        }
        break;

      default:
        $global['instance_count'] = $global['instance_count'] + $individual_search['instance_count'];
        foreach ($individual_search['text_ids'] as $id => $text_data) {
          // Get a *combined* list of all text ids matching search criteria.
          $global['text_ids'][$id] = 1;
        }
        break;
    }
    return $global;
  }

  /**
   * Parse the query for user-supplied search parameters.
   */
  protected static function getConditions($parameters, $facet_map) {
    $conditions = [];
    foreach (array_keys(TextMetadata::$facetIDs) as $id) {
      if (isset($parameters[$id])) {
        $condition = Xss::filter($parameters[$id]);
        $param_list = explode("+", $condition);
        foreach ($param_list as $param) {
          if (!empty($facet_map['by_name'][$id][$param])) {
            $conditions[$id][] = $facet_map['by_name'][$id][$param];
          }
        }
      }
    }
    if (isset($parameters['id'])) {
      $conditions['id'] = Xss::filter($parameters['id']);
    }
    if (isset($parameters['toefl_total_min'])) {
      $conditions['toefl_total_min'] = Xss::filter($parameters['toefl_total_min']);
    }
    if (isset($parameters['toefl_total_max'])) {
      $conditions['toefl_total_max'] = Xss::filter($parameters['toefl_total_max']);
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
   * Helper function to get a specific cache id.
   */
  private static function getCacheString($request) {
    $cachestring = 'corpus_search_output_' . $request->getRequestUri();
    return md5($cachestring);
  }

  /**
   * Helper method to direct the type of search to the search method.
   */
  protected static function getIndividualSearchResults($token, $type, $conditions, $method) {
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
        $data = Search::wordSearch($cleaned, $conditions, 'sensitive');
        break;

      case 'word':
        $data = Search::wordSearch($token, $conditions, 'insensitive', $method);
        break;
    }
    return $data;
  }

}
