<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Controllers;

use Icinga\Application\Config;
use Icinga\Application\Hook\AuthenticationHook;
use Icinga\Application\Icinga;
use Icinga\Crypt\RSA;
use Icinga\Forms\Authentication\LoginForm;
use Icinga\Rememberme\Common\Database;
use Icinga\User;
use Icinga\Web\Controller;
use Icinga\Web\Helper\CookieHelper;
use Icinga\Web\RememberMeCookie;
use Icinga\Web\Url;
use ipl\Sql\Select;

/**
 * Application wide controller for authentication
 */
class AuthenticationController extends Controller
{
    use database;
    /**
     * {@inheritdoc}
     */
    protected $requiresAuthentication = false;

    /**
     * {@inheritdoc}
     */
    protected $innerLayout = 'inline';

    /**
     * Log into the application
     */
    public function loginAction()
    {
        $icinga = Icinga::app();
        if (($requiresSetup = $icinga->requiresSetup()) && $icinga->setupTokenExists()) {
            $this->redirectNow(Url::fromPath('setup'));
        }
        $form = new LoginForm();
        if (isset($_COOKIE['remember-me'])) {
            $data = explode('|', $_COOKIE['remember-me']);
            $publicKeyEncoded = array_pop($data);

            $select = (new Select())
                ->from('rememberme')
                ->columns('*')
                ->where(['public_key = ?' => $publicKeyEncoded]);

            $DBData = $this->getDb()->select($select)->fetch();

            $newData = array();
            foreach ($DBData as $key => $value) {
                $newData[$key] = $value;
            }

            $rsa = new RSA();
            $rsa->loadKey(base64_decode($newData['private_key']), base64_decode($publicKeyEncoded));
            list($username, $passwordFromCookie) = $rsa->decryptFromBase64(...$data);

            $authChain = $this->Auth()->getAuthChain();
            $authChain->setSkipExternalBackends(true);
            $user = new User($username);
            if (! $user->hasDomain()) {
                $user->setDomain(Config::app()->get('authentication', 'default_domain'));
            }
            $authenticated = $authChain->authenticate($user, $passwordFromCookie);
            if ($authenticated) {
                $this->Auth()->setAuthenticated($user);
            }
        }
        if ($this->Auth()->isAuthenticated()) {
            // Call provided AuthenticationHook(s) when login action is called
            // but icinga web user is already authenticated
            AuthenticationHook::triggerLogin($this->Auth()->getUser());
            $this->redirectNow($form->getRedirectUrl());
        }
        if (! $requiresSetup) {
            $cookies = new CookieHelper($this->getRequest());
            if (! $cookies->isSupported()) {
                $this
                    ->getResponse()
                    ->setBody("Cookies must be enabled to run this application.\n")
                    ->setHttpResponseCode(403)
                    ->sendResponse();
                exit;
            }
            $form->handleRequest();
        }

        $this->view->form = $form;
        $this->view->defaultTitle = $this->translate('Icinga Web 2 Login');
        $this->view->requiresSetup = $requiresSetup;
    }

    /**
     * Log out the current user
     */
    public function logoutAction()
    {
        $auth = $this->Auth();
        if (isset($_COOKIE['remember-me'])) {
            unset($_COOKIE['remember-me']);
            $this->getResponse()->setCookie(
                (new RememberMeCookie(time() - 3600))->setValue('')
            );
        }
        $this->getDb()->delete('rememberme', ['username = ?' => $auth->getUser()->getUsername()]);

        if (! $auth->isAuthenticated()) {
            $this->redirectToLogin();
        }
        // Get info whether the user is externally authenticated before removing authorization which destroys the
        // session and the user object
        $isExternalUser = $auth->getUser()->isExternalUser();
        // Call provided AuthenticationHook(s) when logout action is called
        AuthenticationHook::triggerLogout($auth->getUser());
        $auth->removeAuthorization();
        if ($isExternalUser) {
            $this->view->layout()->setLayout('external-logout');
            $this->getResponse()->setHttpResponseCode(401);
        } else {
            $this->redirectToLogin();
        }
    }
}
