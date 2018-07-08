<?php

namespace Drupal\corpus_api_texts;

/**
 * PHP Implementation of a Keyword-in-Context search.
 */
class Kwic {

  /**
   * Main function to provide keyword-in-context.
   *
   * @return string
   *   The highlighted keywords in context.
   */
  public static function excerpt($text, $search_string, $instances = '5') {
    $sentences = self::getSentences($text);
    $keywords = self::splitKeywords($search_string);
    $instances = self::getInstances($sentences, $keywords, $instances);
    $excerpt = $instances;
    return $excerpt;
  }

  public static function splitKeywords($search_string) {
    $keys = [];
    preg_match_all("/\"([^\"]+)\"/u", $search_string, $phrases);
    if (empty($phrases[1])) {
      // There are no quoted strings. Just return each word separately.
      return explode(' ', $search_string);
    }
    else {
      foreach ($phrases[1] as $phrase) {
        $keys[] = $phrase;
      }
      // There are quoted strings. Next check for additional, unquoted strings.
      $unquoted = preg_split("/\"([^\"]+)\"/u", $search_string);
      if (!empty($unquoted)) {
        foreach ($unquoted as $string) {
          $cleaned = trim($string);
          if (strlen($cleaned) > 0) {
          $keys[] = $cleaned;
          }
        }
      }
    }
    return $keys;
  }

  public static function getSentences($text) {
    $sentence = new Sentence();
    $sentences = $sentence->split($text);
    return $sentences;
  }

  public static function getInstances($sentences, $phrases, $inc) {
    $instances = [];
    foreach ($sentences as $sentence) {
      $original_sentence = $sentence;
      if (count($instances) >= $inc) {
        break;
      }
      foreach ($phrases as $phrase) {
        preg_match("/[^\w\s]/", $phrase, $punctuation);
        $boundary = '((?<![\w!@#$^%,+])(?=[\w!@#$^%,+])|(?<=[\w!@#$^%,+])(?![\w!@#$^%,+]))';
        if (empty($punctuation)) {
          $boundary = '\b';
        }

        $sentence = preg_replace('/' . $boundary . preg_quote($phrase, "/") . $boundary . '/i', "<mark>\$0</mark>", $sentence);
      }
      if ($sentence !== $original_sentence) {
        $instances[] = $sentence;
      }
    }
    return $instances;
  }

}
