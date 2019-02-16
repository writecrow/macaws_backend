<?php

namespace Drupal\corpus_search;

use Drupal\node\Entity\Node;

/**
 * Class CorpusWordFrequency.
 *
 * @package Drupal\corpus_search
 */
class CorpusWordFrequency {

  /**
   * Main method: retrieve all texts & count words sequentially.
   */
  public static function analyze() {
    if (PHP_SAPI == 'cli' && function_exists('drush_main')) {
      ini_set("memory_limit", "4096M");
      print_r('Analyzing word frequency...' . PHP_EOL);
      if ($texts = self::retrieve()) {
        if (!empty($texts)) {
          $inc = 0;
          foreach ($texts as $key => $text) {
            $result = self::count($text);
            print_r($result . PHP_EOL);
          }
        }
      }
    }
    else {
      // Convert files into machine-readable array.
      $texts = self::retrieve();
      drupal_set_message(count($texts) . ' texts analyzed.');

      // Save valid texts.
      foreach ($texts as $text) {
        $operations[] = [
          ['\Drupal\word_frequency\FrequencyService', 'processor'],
          [$text],
        ];
      }

      $batch = [
        'title' => t('Calculating Frequency'),
        'operations' => $operations,
        'finished' => ['\Drupal\word_frequency\FrequencyService', 'finish'],
        'file' => drupal_get_path('module', 'word_frequency') . '/word_frequency.module',
      ];

      batch_set($batch);
    }
  }

  /**
   * Retrieve which entities should be counted.
   *
   * @return int[]
   *   IDs of texts
   */
  protected static function retrieve() {
    $nids = \Drupal::entityQuery('node')->condition('type', 'text')->execute();
    if (!empty($nids)) {
      return(array_values($nids));
    }
    return FALSE;
  }

  /**
   * Batch processor for counting.
   *
   * @param int $node_id
   *   An individual node id.
   * @param str[] $context
   *   Operational context for batch processes.
   */
  public static function processor($node_id, array &$context) {
    $result = self::count($node_id);
    if ($result) {
      $context['results'][$node_id][] = $node_id;
    }
  }

  /**
   * Count words in an individual entity.
   *
   * @param int $node_id
   *   An individual node id.
   */
  public static function count($node_id) {
    $result = FALSE;
    $node = Node::load($node_id);
    if ($body = $node->field_body->getValue()) {
      $tokens = self::tokenize($body[0]['value']);
      foreach ($tokens as $word) {
        if (isset($frequency[$word])) {
          $frequency[$word]++;
        }
        else {
          $frequency[$word] = 1;
        }
      }
      if (!empty($frequency)) {
        foreach ($frequency as $word => $count) {
          if (strlen($word) > 250) {
            continue;
          }
          $connection = \Drupal::database();
          $connection->merge('corpus_word_frequency')
            ->key(['word' => utf8_decode($word)])
            ->fields([
              'count' => $count,
              'texts' => 1,
              'ids' => $node_id . ":" . $count,
            ])
            ->expression('count', 'count + :inc', [':inc' => $count])
            ->expression('texts', 'texts + 1')
            ->expression('ids', "concat(ids, ',' :node_id)", [':node_id' => $node_id . ":" . $count])
            ->execute();
        }
      }
      $result = $node_id;
    }
    return $result;
  }

  /**
   * Split on word boundaries.
   */
  public static function tokenize($string) {
    $tokens = preg_split("/\s|[,.!?;\"”]/", $string);
    $result = [];
    $strip_chars = ":,.!&\?;\”'()#$^%@*";
    foreach ($tokens as $token) {
      $token = trim($token, $strip_chars);
      if ($token) {
        $result[] = $token;
      }
    }
    return $result;
  }

  /**
   * Batch API callback.
   */
  public static function finish($success, $results, $operations) {
    if (!$success) {
      $message = t('Finished, with possible errors.');
      drupal_set_message($message, 'warning');
    }
    if (isset($results['updated'])) {
      drupal_set_message(count($results['updated']) . ' texts updated.');
    }
    if (isset($results['created'])) {
      drupal_set_message(count($results['created']) . ' texts analyzed.');
    }

  }

  /**
   * Callback function to truncate the table.
   */
  public static function wipe() {
    $connection = \Drupal::database();
    $query = $connection->delete('corpus_word_frequency');
    $query->execute();
  }

}
