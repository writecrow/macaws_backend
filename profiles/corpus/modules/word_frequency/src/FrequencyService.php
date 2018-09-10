<?php

namespace Drupal\word_frequency;

use Drupal\Component\Utility\Html;
use Drupal\node\Entity\Node;

/**
 * Class ImporterService.
 *
 * @package Drupal\corpus_importer
 */
class FrequencyService {

  public static function mostCommon($range = 100) {
    // Create an object of type Select and directly 
    // add extra detail to this query object: a condition, fields and a range
    $connection = \Drupal::database();
    $query = $connection->select('word_frequency', 'f')
      ->fields('f', ['word', 'count'])
      ->orderBy('count', 'DESC')
      ->range(0, $range); 
    $result = $query->execute();
    return $result->fetchAllKeyed();   
  }  

  /**
   * Main method: retrieve all texts & count words sequentially.
   */
  public static function analyze() {
    if (PHP_SAPI == 'cli' && function_exists('drush_main')) {
      ini_set("memory_limit", "4096M");
      if ($texts = self::retrieve()) {
        foreach ($texts as $text) {
          $result = self::count($text);
          print_r($result . PHP_EOL);
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
    $nids = \Drupal::entityQuery('node')->condition('type','text')->execute();
    if (!empty($nids)) {
      return(array_keys($nids));
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
      foreach ($tokens as $raw) {
        $word = strtolower($raw);
        if (isset($frequency[$word])) {
          $frequency[$word]++;
        }
        else {
          $frequency[$word] = 1;
        }
      }
      if (!empty($frequency)) {
        foreach($frequency as $word => $count) {
          $connection = \Drupal::database();
          $connection->merge('word_frequency')
            ->key(['word' => $word])
            ->fields([
                'count' => $count,
            ])
            ->expression('count', 'count + :inc', [':inc' => $count])
            ->execute();
        }
      }
      $result = $node_id;
    }
    return $result;
  }

    public function tokenize($str) {
      $arr = array();
      // for the character classes
      // see http://php.net/manual/en/regexp.reference.unicode.php
      $pat = '/
            ([\pZ\pC]*)			# match any separator or other
                                # in sequence
            (
                [^\pP\pZ\pC]+ |	# match a sequence of characters
                                # that are not punctuation,
                                # separator or other
                .				# match punctuations one by one
            )
            ([\pZ\pC]*)			# match a sequence of separators
                                # that follows
        /xu';
      preg_match_all($pat,$str,$arr);
      return $arr[2];
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

}
