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

  public function search(Request $request) {
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
        $prepared[$piece] = 'quoted';
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
      $result[$term]['raw'] = $count;
      $result[$term]['normed'] = number_format($count * $ratio);
    }
    $response->setContent(json_encode($result));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

  public function totalWords(Request $request) {
    $response = new Response();
    $count = FrequencyService::totalWords();
    $response->setContent(json_encode(array('total' => $count)));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }
}
