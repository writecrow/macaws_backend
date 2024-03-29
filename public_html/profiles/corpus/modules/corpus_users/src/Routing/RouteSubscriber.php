<?php

namespace Drupal\corpus_users\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Class RouteSubscriber.
 *
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('user.login.http')) {
      $route->setDefault('_controller', 'Drupal\corpus_users\Controller\LoginEmailUsernameController::login');
    }
    if ($route = $collection->get('user.pass.http')) {
      $route->setDefault('_controller', 'Drupal\corpus_users\Controller\LoginEmailUsernameController::resetPassword');
    }
  }

}
