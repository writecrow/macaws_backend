<?php

namespace Drupal\corpus_users\Controller;


use Drupal\user\Controller\UserAuthenticationController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class LoginEmailUsernameController extends UserAuthenticationController {

  /**
   * @inheritDoc
   */
  public function login(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $credentials = $this->serializer->decode($content, $format);
    if (!isset($credentials['name']) && !isset($credentials['pass'])) {
      throw new BadRequestHttpException('Missing credentials.');
    }

    if (!isset($credentials['name'])) {
      throw new BadRequestHttpException('Missing credentials.name.');
    }

    // Check if a user exists with that name.
    if (!user_load_by_name($credentials['name'])) {
      // If not, assume the name is an email address and try to load that way
      if ($loadUser = user_load_by_mail($credentials['name'])) {
        // If this works, then set the array value to this user's account name
        // so we don't have to alter any other logic.
        $credentials['name'] = $loadUser->getAccountName();
      }
    }

    if (!isset($credentials['pass'])) {
      throw new BadRequestHttpException('Missing credentials.pass.');
    }

    $this->floodControl($request, $credentials['name']);

    if ($this->userIsBlocked($credentials['name'])) {
      throw new BadRequestHttpException('The user has not been activated or is blocked.');
    }

    if ($uid = $this->userAuth->authenticate($credentials['name'], $credentials['pass'])) {
      $this->flood->clear('user.http_login', $this->getLoginFloodIdentifier($request, $credentials['name']));
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->userStorage->load($uid);
      $this->userLoginFinalize($user);

      // Send basic metadata about the logged in user.
      $response_data = [];
      if ($user->get('uid')->access('view', $user)) {
        $response_data['current_user']['uid'] = $user->id();
      }
      if ($user->get('roles')->access('view', $user)) {
        $response_data['current_user']['roles'] = $user->getRoles();
      }
      $response_data['current_user']['roles'] = $user->getRoles();
      if ($user->get('name')->access('view', $user)) {
        $response_data['current_user']['name'] = $user->getAccountName();
      }
      $response_data['csrf_token'] = $this->csrfToken->get('rest');


      $logout_route = $this->routeProvider->getRouteByName('user.logout.http');
      // Trim '/' off path to match \Drupal\Core\Access\CsrfAccessCheck.
      $logout_path = ltrim($logout_route->getPath(), '/');
      $response_data['logout_token'] = $this->csrfToken->get($logout_path);

      $encoded_response_data = $this->serializer->encode($response_data, $format);
      return new Response($encoded_response_data);
    }

    $flood_config = $this->config('user.flood');
    if ($identifier = $this->getLoginFloodIdentifier($request, $credentials['name'])) {
      $this->flood->register('user.http_login', $flood_config->get('user_window'), $identifier);
    }
    // Always register an IP-based failed login event.
    $this->flood->register('user.failed_login_ip', $flood_config->get('ip_window'));
    throw new BadRequestHttpException('Sorry, unrecognized username or password.');
  }

  /**
   * Resets a user password.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function resetPassword(Request $request) {
    $format = $this->getRequestFormat($request);

    $content = $request->getContent();
    $credentials = $this->serializer->decode($content, $format);

    // Check if a name or mail is provided.
    if (!isset($credentials['name']) && !isset($credentials['mail'])) {
      throw new BadRequestHttpException('Missing credentials.name or credentials.mail');
    }

    // Load by name if provided.
    if (isset($credentials['name'])) {
      $users = $this->userStorage->loadByProperties(['name' => trim($credentials['name'])]);
      if (empty($users)) {
        // try email
        $users = $this->userStorage->loadByProperties(['mail' => trim($credentials['name'])]);
      }
    }
    elseif (isset($credentials['mail'])) {
      $users = $this->userStorage->loadByProperties(['mail' => trim($credentials['mail'])]);
      if (empty($users)) {
        // try name
        $users = $this->userStorage->loadByProperties(['name' => trim($credentials['mail'])]);
      }
    }

    /** @var \Drupal\Core\Session\AccountInterface $account */
    $account = reset($users);
    if ($account && $account->id()) {
      if ($this->userIsBlocked($account->getAccountName())) {
        throw new BadRequestHttpException('The user has not been activated or is blocked.');
      }

      // Send the password reset email.
      $mail = _user_mail_notify('password_reset', $account, $account->getPreferredLangcode());
      if (empty($mail)) {
        throw new BadRequestHttpException('Unable to send email. Contact the site administrator if the problem persists.');
      }
      else {
        $this->logger->notice('Password reset instructions mailed to %name at %email.', [
          '%name' => $account->getAccountName(),
          '%email' => $account->getEmail(),
        ]);
        return new Response();
      }
    }

    // Error if no users found with provided name or mail.
    throw new BadRequestHttpException('Unrecognized username or email address.');
  }

}
