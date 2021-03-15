<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Controllers;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Application\Icinga;
use Icinga\Forms\Dashboard\AvailableDashlets;
use Icinga\Forms\Dashboard\HomeAndPaneForm;
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
            $redirectUrl = $dashletForm->getValue('home');
            if ($dashletForm->getPopulatedValue('remove_dashlet')) {
                $redirectUrl = $this->_request->getParam('home');
            }

            $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                'home'  => $redirectUrl
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $dashletForm->load($dashlet);
        $this->view->form = $dashletForm;
    }

    public function updatePaneAction()
    {
        $this->getTabs()->add('update-pane', [
            'title' => $this->translate('Update Pane'),
            'url'   => Url::fromRequest()
        ])->activate('update-pane');

        $paneName = $this->_request->getParam('pane');
        if (! $this->dashboard->hasPane($paneName)) {
            throw new HttpNotFoundException('Pane not found');
        }

        $paneForm = (new HomeAndPaneForm($this->dashboard))
            ->setAction((string)Url::fromRequest())
            ->on(HomeAndPaneForm::ON_SUCCESS, function () {
                $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                    'home'  => $this->_request->getParam('home')
                ]));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $paneForm;
    }

    public function updateHomeAction()
    {
        if (! $this->_request->getParam('home')) {
            throw new Zend_Controller_Action_Exception(
                'Missing parameter "home"',
                400
            );
        }

        $homeForm = new HomeAndPaneForm($this->dashboard);
        $homeForm->setAction((string)Url::fromRequest())
            ->on(HomeAndPaneForm::ON_SUCCESS, function () use ($homeForm) {
                // Check which button has triggered the on SUCCESS event because each button
                // has a different redirect url
                if ($homeForm->getPopulatedValue('btn_remove')) {
                    $homes = $this->dashboard->getHomes();
                    // Since the navigation menu is not loaded that fast, we need to unset
                    // the just deleted home from this array as well.
                    unset($homes[$this->_request->getParam('home')]);

                    $firstHome = reset($homes);
                    if (empty($firstHome)) {
                        $this->redirectNow('dashboard');
                    } else {
                        $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                            'home'  => $firstHome->getName()
                        ]));
                    }
                } else {
                    $this->redirectNow(Url::fromPath('dashboard/settings')->addParams([
                        'home'  => $homeForm->getValue('name')
                    ]));
                }
            })->handleRequest(ServerRequest::fromGlobals());

        $this->view->form = $homeForm;
    }

    public function homeAction()
    {
        $dashboardHome = $this->_request->getParam('home');
        $this->urlParam = ['home' => $dashboardHome];

        if ($dashboardHome === 'Available Dashlets') {
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
        $homes = $this->dashboard->getHomes();
        if (array_key_exists('Default Dashboards', $homes)) {
            $defaultHome = $homes[t('Default Dashboards')];
            $this->dashboard->loadUserDashboardsFromDatabase($defaultHome->getAttribute('homeId'));
        }

        $this->createTabs();
        if (! $this->dashboard->hasPanes()) {
            $this->view->title = 'Dashboard';
        } else {
            if (empty($this->dashboard->getPanes())) {
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
