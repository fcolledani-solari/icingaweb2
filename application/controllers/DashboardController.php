<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Controllers;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Application\Icinga;
use Icinga\Common\Database;
use Icinga\Forms\Dashboard\AvailableDashlets;
use Icinga\Forms\Dashboard\RemovalForm;
use Icinga\Forms\Dashboard\RenameForm;
use Icinga\Web\Controller\ActionController;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Forms\Dashboard\DashletForm;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Tabextension\DashboardSettings;
use Zend_Controller_Action_Exception;
use ipl\Web\Url;

/**
 * Handle creation, removal and displaying of dashboards, panes and dashlets
 *
 * @see Icinga\Web\Widget\Dashboard for more information about dashboards
 */
class DashboardController extends ActionController
{
    use Database;

    /**
     * @var Dashboard;
     */
    private $dashboard;

    public function init()
    {
        $this->dashboard = new Dashboard();
        $this->dashboard->setUser($this->Auth()->getUser());
        $this->dashboard->load();
    }

    public function newDashletAction()
    {
        $this->getTabs()->add('new-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('New Dashlet'),
            'url'       => Url::fromRequest()
        ));

        $dashletForm = new DashletForm($this->dashboard);
        $dashletForm->on(DashletForm::ON_SUCCESS, function () use ($dashletForm) {
            $this->redirectNow(Url::fromPath('dashboard/home')->addParams([
                'home'  => $dashletForm->getValue('home'),
                'pane'  => $dashletForm->getValue('pane'),
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        if ($this->_request->getParam('url')) {
            $params = $this->_request->getParams();
            $params['url'] = rawurldecode($this->_request->getParam('url'));
            $dashletForm->populate($params);
        }

        $this->view->form = $dashletForm;
    }

    public function updateDashletAction()
    {
        $this->getTabs()->add('update-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('Update Dashlet'),
            'url'       => Url::fromRequest()
        ));

        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        if (! $this->_request->getParam('dashlet')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "dashlet"',
                400
            );
        }

        $pane = $this->dashboard->getPane($this->getParam('pane'));
        $dashlet = $pane->getDashlet($this->getParam('dashlet'));

        $dashletForm = new DashletForm($this->dashboard);
        $dashletForm->on(DashletForm::ON_SUCCESS, function () use ($dashletForm) {
            $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                'home'  => $dashletForm->getValue('home')
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $dashletForm->load($dashlet);
        $this->view->form = $dashletForm;
    }

    public function removeDashletAction()
    {
        $this->getTabs()->add('remove-dashlet', array(
            'active'    => true,
            'label'     => $this->translate('Remove Dashlet'),
            'url'       => Url::fromRequest()
        ));

        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        if (! $this->_request->getParam('dashlet')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "dashlet"',
                400
            );
        }

        $pane = $this->_request->getParam('pane');
        $paneForm = (new RemovalForm($this->dashboard, $pane))
            ->on(RemovalForm::ON_SUCCESS, function () {
                $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                    'home'  => $this->getRequest()->getParam('home')
                ]));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->pane = $pane;
        $this->view->dashlet = $this->dashboard->getPane($pane)->getDashlet($this->getRequest()->getParam('dashlet'));
        $this->view->form = $paneForm;
    }

    public function renamePaneAction()
    {
        $this->getTabs()->add('update-pane', [
            'title' => $this->translate('Update Pane'),
            'url'   => Url::fromRequest()
        ])->activate('update-pane');

        $paneName = $this->_request->getParam('pane');
        if (! $this->dashboard->hasPane($paneName)) {
            throw new HttpNotFoundException('Pane not found');
        }

        $paneForm = (new RenameForm($this->dashboard))
            ->on(RenameForm::ON_SUCCESS, function () {
                $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                    'home'  => $this->getRequest()->getParam('home')
                ]));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $paneForm;
    }

    public function removePaneAction()
    {
        if (! $this->_request->getParam('pane')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "pane"',
                400
            );
        }
        $pane = $this->_request->getParam('pane');
        $this->getTabs()->add('remove-pane', [
            'active'    => true,
            'title'     => sprintf($this->translate('Remove Dashboard: %s'), $pane),
            'url'       => Url::fromRequest()
        ]);

        $paneForm = (new RemovalForm($this->dashboard, $pane))
            ->on(RemovalForm::ON_SUCCESS, function () {
                $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                    'home'  => $this->getRequest()->getParam('home')
                ]));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $paneForm;
    }

    public function renameHomeAction()
    {
        if (! $this->_request->getParam('home')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "home"',
                400
            );
        }

        $home = $this->_request->getParam('home');
        $this->getTabs()->add('rename-home', [
            'active'    => true,
            'title'     => sprintf($this->translate('Rename Home: %s'), $home),
            'url'       => Url::fromRequest()
        ]);

        $homeForm = new RenameForm($this->dashboard);
        $homeForm->on(RemovalForm::ON_SUCCESS, function () use ($home, $homeForm) {
            $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                'home'  => $homeForm->getValue('name')
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $homeForm;
    }

    public function removeHomeAction()
    {
        if (! $this->_request->getParam('home')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "home"',
                400
            );
        }

        $home = $this->_request->getParam('home');
        $this->getTabs()->add('remove-home', [
            'active'    => true,
            'title'     => sprintf($this->translate('Remove Home: %s'), $home),
            'url'       => Url::fromRequest()
        ]);

        $homeForm = (new RemovalForm($this->dashboard))
            ->on(RemovalForm::ON_SUCCESS, function () {
                $homes = $this->dashboard->getHomes();
                $firstHome = reset($homes);

                if ($firstHome->getName() === $this->getRequest()->getParam('home')) {
                    $this->redirectNow('dashboard');
                } else {
                    $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                        'home'  => $firstHome->getName()
                    ]));
                }
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $homeForm;
    }

    public function homeAction()
    {
        $dashboardHome = $this->_request->getParam('home');
        $homeOwner = $this->dashboard->getHomes()[$dashboardHome]->getAttribute('owner');
        $this->urlParam = ['home' => $dashboardHome];

        if ($dashboardHome === 'Available Dashlets' || $homeOwner === null) {
            $this->view->tabeleView = true;

            $this->getTabs()->add($dashboardHome, [
                'label' => $dashboardHome,
                'url'   => Url::fromRequest()
            ])->activate($dashboardHome);

            if ($dashboardHome === 'Available Dashlets') {
                $moduleManager = Icinga::app()->getModuleManager();
                $dashlets = [];

                foreach ($moduleManager->getLoadedModules() as $module) {
                    if ($this->dashboard->getUser()->can($moduleManager::MODULE_PERMISSION_NS . $module->getName())) {
                        if (empty($module->getDashletHomes())) {
                            continue;
                        }

                        $dashlets[$module->getName()] = $module->getDashletHomes();
                    }
                }

                $dashlet = new AvailableDashlets($dashlets);
                $this->view->dashlets = $dashlet;
            }
        } else {
            $this->createTabs(true);
            // Table view and dashboard/dashlets view have different div contents
            // so we need to set tableView to false
            $this->view->tabeleView = false;

            if ($this->_request->getParam('pane')) {
                $pane = $this->_request->getParam('pane');
                $this->dashboard->activate($pane);
            }

            $this->view->dashboard = $this->dashboard;
        }
    }

    public function homeDetailAction()
    {
        $dashlet = $this->_request->getParam('dashlet');
        $this->getTabs()->add($dashlet, [
            'label' => $this->_request->getParam('module') . ' Dashboard',
            'url'   => Url::fromRequest()
        ])->activate($dashlet);

        $dashletWidget = new Dashboard\Dashlet($dashlet, Url::fromRequest());
        $this->view->dashlets = $dashletWidget;
    }

    /**
     * Display the dashboard with the pane set in the 'pane' request parameter
     *
     * If no pane is submitted or the submitted one doesn't exist, the default pane is
     * displayed (normally the first one)
     */
    public function indexAction()
    {
        $this->createTabs();
        if (! $this->dashboard->hasPanes()) {
            $this->view->title = 'Dashboard';
        } else {
            $panes = array_filter(
                $this->dashboard->getPanes(),
                function ($pane) {
                    return ! $pane->getDisabled();
                }
            );
            if (empty($panes)) {
                $this->view->title = 'Dashboard';
                $this->getTabs()->add('dashboard', array(
                    'active'    => true,
                    'title'     => $this->translate('Dashboard'),
                    'url'       => Url::fromRequest()
                ));
            } else {
                if ($this->_getParam('pane')) {
                    $pane = $this->_getParam('pane');
                    $this->dashboard->activate($pane);
                }
                if ($this->dashboard === null) {
                    $this->view->title = 'Dashboard';
                } else {
                    $this->view->title = $this->dashboard->getActivePane()->getTitle() . ' :: Dashboard';
                    if ($this->hasParam('remove')) {
                        $this->dashboard->getActivePane()->removeDashlet($this->getParam('remove'));
                        $this->dashboard->getConfig()->saveIni();
                        $this->redirectNow(Url::fromRequest()->remove('remove'));
                    }
                    $this->view->dashboard = $this->dashboard;
                }
            }
        }
    }

    /**
     * Setting dialog
     */
    public function settingsAction()
    {
        $this->createTabs();
        $controlForm = new Dashboard\SettingSortBox($this->dashboard);
        $controlForm->on(Dashboard\SettingSortBox::ON_SUCCESS, function () use ($controlForm) {
            $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                'home' => $controlForm->getPopulatedValue('sort_dashboard_home')
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $this->view->control = $controlForm;
        $this->view->dashboard = $this->dashboard;
        $this->view->settings = new Dashboard\Settings($this->dashboard);
    }

    /**
     * Create tab aggregation
     *
     * @param  bool  $defaultPanes
     */
    private function createTabs($defaultPanes = false)
    {
        $urlParam = [];
        if ($this->_request->has('home')) {
            $urlParam = ['home' => $this->_request->getParam('home')];
        }
        $this->view->tabs = $this->dashboard->getTabs($defaultPanes)->extend(new DashboardSettings($urlParam));
    }
}
