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
    $results = self::getSearchResults($request, $excerpt_type = "fixed");
    $response = new CacheableResponse('', 200);
    $output = '';
    if (!empty($results['search_results'])) {
      $inc = 0;
      $lines = [];
      foreach ($results['search_results'] as $result) {
        if ($inc > 19) {
          break;
        }
        $inc++;
        preg_match('/<mark>([^<]*)<\/mark>(.[^\w]*)/u', $result['text'], $three);
        $bookends = preg_split('/<mark>([^<]*)<\/mark>(.[^\w]*)/u', $result['text'], 2);
        $start = $bookends[0];
        $end = $bookends[1];
        preg_match('/[^ ]*$/', trim($bookends[0]), $two);
        $one = preg_split('/[^ ]*$/', trim($start));
        preg_match('/(\s*)([^\s]*)(.*)/', $end, $trailing);
        $before_length = mb_strlen($one[0] . $two[0]);
        if ($before_length < 60) {
          $makeup = 60 - $before_length;
          $first = str_repeat("&nbsp;", $makeup) . $one[0];
        }
        else {
          $first = $one[0];
        }
        $second = empty($two[0]) ? ' ' : $two[0];
        $lines[] = [$first, $second, $three[0], $trailing[2], $trailing[3] . '<br>'];
      }
      $json_lines = json_encode($lines);
      $output .= "<style>
        body {
          font-size: 14px;
          font-family: 'Lucida Console', Monaco, monospace;
          width: 1200px;
        }
        #concordance_lines {
          text-align: center;
        }
      </style>";
      $output .= '
        <div id="concordance_lines"></div>

        <script type="text/javascript">

        // lines is a list of lists with 5 items each:
        // 0: context before
        // 1: word right before kwic
        // 2: kwic
        // 3: word right after kwic
        // 4: context after

        lines = ' . $json_lines . ' 

        // comparator function to sort by word after the kwic
        function comparator_after(a, b) {
          first = a[3] === "" ? " " : a[3].toLowerCase();
          second = b[3] === "" ? " " : b[3].toLowerCase();
          if (first < second) return -1;
          if (first > second) return 1;
          // The two strings are equal. Compare the final string...
          first = a[4] === "" ? " " : a[4].trim().toLowerCase();
          second = b[4] === "" ? " " : b[4].trim().toLowerCase();
          if (first < second) return -1;
          if (first > second) return 1;
          return 0;
        }

        // comparator function to sort by word before the kwic
        function comparator_before(a, b) {
          first =  a[1] === "" ? " " : a[1].toLowerCase();
          second = b[1] === "" ? " " : b[1].toLowerCase();
          if (first < second) return -1;
          if (first > second) return 1;
          return 0;
        }

        // function to sort lines by the word after the kwic
        function sort_after() {
          sorted_lines = lines.sort(comparator_after);

          const conc_line_div = document.getElementById("concordance_lines");
          conc_line_div.innerHTML = "The lines below are sorted by the word right <strong>after</strong> the key word in context. <a href=\"#\" onclick=\"sort_before();return false;\">Sort by the word before</a>.<hr>";

          for (const line of sorted_lines) {
            conc_line_div.innerHTML += line.join(" ");
          }

        }

        // function to sort lines by the word before the kwic
        function sort_before() {
          sorted_lines = lines.sort(comparator_before);

          const conc_line_div = document.getElementById("concordance_lines");
          conc_line_div.innerHTML = "The lines below are sorted by the word right <strong>before</strong> the key word in context. <a href=\"#\" onclick=\"sort_after();return false;\">Sort by the word after</a>.<hr>";

          for (const line of sorted_lines) {
            conc_line_div.innerHTML += line.join(" ");
          }
        }
        // start with lines sorted by the word before the kwic
        sort_before();
        </script>';
      $response->setContent($output);
    }
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }

}
