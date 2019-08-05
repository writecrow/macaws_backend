<?php

namespace Drupal\corpus_search\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Corpus Search excerpt embed endpoint.
 *
 * @package Drupal\corpus_search\Controller
 */
class ExcerptEmbed extends CorpusSearch {

  /**
   * The Controller endpoint -- for testing purposes.
   *
   * The actual REST endpoint is
   * Drupal\corpus_search\Plugin\rest\resource\CorpusSearch.
   */
  public static function endpoint(Request $request) {
    // Response.
    $results = self::getSearchResults($request);
    $response = new CacheableResponse('', 200);
    if (!empty($results['search_results'])) {
      $output ="<style>
        table {
            border-collapse: collapse;
          }
        td, th {
          border: 1px solid #dddddd;
          text-align: left;
          padding: 8px;
        }

        tr:nth-child(even) {
          background-color: #f5f5f5;
        }
      </style>";
      $output .= '<table>';
      $inc = 0;
      foreach ($results['search_results'] as $result) {
        if ($inc > 9) {
          break;
        }
        $inc++;
        $output .= "<tr><td>" . $result['text'] . '</td></tr>';
      }
      $output .= "</table>";
      $response->setContent($output);
    }
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }
}
