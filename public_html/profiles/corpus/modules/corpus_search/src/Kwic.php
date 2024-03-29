<?php

namespace Drupal\corpus_search;

use writecrow\Highlighter\Highlighter;
use Drupal\corpus_search\Controller\CorpusSearch;
use Drupal\corpus_search\Sentence;

/**
 * PHP Implementation of a Keyword-in-Context search.
 */
class Kwic {

  /**
   * Given a text and a string of search terms, return highlighted sentences.
   *
   * @return array
   *   The highlighted keywords in isolated sentences.
   */
  public static function excerpt($text, $search_string, $instances = '5') {
    $sentences = new Sentence();
    $keywords = CorpusSearch::getTokens($search_string);
    return self::getInstances($sentences->split($text), $keywords, $instances);
  }

  public static function getSentences($text) {
    $sentence = new Sentence();
    $sentences = $sentence->split($text);
    return $sentences;
  }

  public static function getInstances($sentences, $keywords, $inc) {
    $instances = [];
    foreach ($sentences as $sentence) {
      if (count($instances) >= $inc) {
        break;
      }
      $sentence = Highlighter::process($sentence, array_keys($keywords), FALSE, "sentence");
      if (mb_strpos($sentence, '<mark>') !== FALSE) {
        $instances[] = $sentence;
      }
    }
    return $instances;
  }

}
