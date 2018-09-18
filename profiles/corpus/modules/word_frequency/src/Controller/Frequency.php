<?php

namespace Drupal\word_frequency\Controller;

use Drupal\word_frequency\FrequencyService;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Frequency.
 *
 * @package Drupal\word_frequency\Controller
 */
class Frequency extends ControllerBase {

  /**
   * Given a search string, tokenize & return frequency data.
   */
  public function wordSearch(Request $request) {
    $response = new Response();
    $case = 'insensitive';
    $total = FrequencyService::totalWords();
    $ratio = 10000 / $total;
    $search = \Drupal::request()->query->get('search');
    // First check for quoted terms.
    $pieces = explode(" ", $search);
    $prepared = [];
    foreach ($pieces as $piece) {
      $length = strlen($piece);
      if ((substr($piece, 0, 1) == '"') && (substr($piece, $length - 1, 1) == '"')) {
        $cleaned = substr($piece, 1, $length - 2);
        $prepared[$cleaned] = 'quoted';
      }
      else {
        $prepared[$piece] = 'standard';
      }
    }
    foreach ($prepared as $word => $type) {
      $search = FrequencyService::tokenize($word);
      if ($type == 'quoted') {
        $count = FrequencyService::simpleSearch($search[0], 'sensitive');
        $term = '"' . $search[0] . '"';
      }
      else {
        $count = FrequencyService::simpleSearch($search[0]);
        $term = $search[0];
      }
      $result[$term]['raw'] = $count['count'];
      $result[$term]['normed'] = number_format($count['count'] * $ratio);
      $result[$term]['texts'] = $count['texts'];
    }
    $output['terms'] = $result;
    // Only run if multiple words have been supplied.
    if (count($result) > 1) {
      $totals['raw'] = 0;
      $totals['normed'] = 0;
      $totals['texts'] = 0;
      foreach ($result as $i) {
        $totals['raw'] = $totals['raw'] + $i['raw'];
        $totals['normed'] = $totals['normed'] + $i['normed'];
      }
      // Get total texts containing at least 1 instance of any of the words.
      $totals['texts'] = FrequencyService::countTextsContaining($prepared);
      $output['totals'] = $totals;
    }
    $response->setContent(json_encode($output));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  /**
   * Given a search string, return non-tokenized frequency data.
   */
  public function phraseSearch(Request $request) {
    $response = new Response();
    $count = [];
    if ($search = \Drupal::request()->query->get('search')) {
      $count = FrequencyService::phraseSearch($search);
    }
    $response->setContent(json_encode($count));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  /**
   * Return the current wordcount of the entire corpus.
   */
  public function totalWords() {
    $response = new Response();
    $count = FrequencyService::totalWords();
    $response->setContent(json_encode(array('total' => $count)));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

}
