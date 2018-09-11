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

  public function search($search, Request $request) {
    $response = new Response();
    $count = FrequencyService::simpleSearch($search);
    $response->setContent(json_encode(array($search => $count)));
    $response->headers->set('Content-Type', 'application/json');
    return $response;
  }

}
