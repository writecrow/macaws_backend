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
    $normalization = \Drupal::request()->query->get('normalization');
    if ($normalization) {
      $total = FrequencyService::totalWords();
      $ratio = (int) $normalization / $total;
    }
    $search = \Drupal::request()->query->get('search');
    $search_terms = FrequencyService::tokenize($search);
    if ($sensitivity = \Drupal::request()->query->get('case')) {
      $case = 'sensitive';
    }
    foreach ($search_terms as $term) {
      $count = FrequencyService::simpleSearch($term, $case);
      if ($normalization) {
        $count = number_format($count * $ratio);
      }
      $result[$term] = $count;
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
