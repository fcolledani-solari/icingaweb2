<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Dashboard;

use Icinga\Exception\ProgrammingError;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Dashboard\Dashlet;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

/**
 * Form to add an url a dashboard pane
 */
class DashletForm extends CompatForm
{
    /** @var Dashboard */
    private $dashboard;

    /** @var array dashboard pane items name=>title */
    private $panes = [];

    public $paneName;

    /**
     * DashletForm constructor.
     *
     * @param Dashboard $dashboard
     */
    public function __construct(Dashboard $dashboard)
    {
        $this->setDashboard($dashboard);
    }

    /**
     * @inheritdoc
     *
     * @return bool
     */
    public function hasBeenSubmitted()
    {
        return $this->hasBeenSent()
            && ($this->getPopulatedValue('remove_dashlet')
                || $this->getPopulatedValue('submit'));
    }

    protected function assemble()
    {
        $dashboardHomes = [];
        $home = Url::fromRequest()->getParam('home');
        $populated = $this->getPopulatedValue('home');

        if ($this->dashboard) {
            $dashboardHomes = $this->dashboard->getHomeKeyNameArray();
            if (empty($populated) && ! empty($home)) {
                $dashboardHomes = $this->dashboard->changeElementPos($dashboardHomes, $home);
            }

            if (empty($populated) && $this->getPopulatedValue('create_new_home') !== 'y') {
                if (! empty($home)) {
                    $this->panes = $this->dashboard->getPaneKeyTitleArray();
                } else {
                    // This tab was opened from where the home parameter is not present
                    $firstHome = $this->dashboard->rewindHomes();
                    if (! empty($firstHome)) {
                        // Load dashboards from the DB by the given home Id
                        $this->dashboard->loadUserDashboardsFromDatabase($firstHome->getAttribute('homeId'));
                        $this->panes = $this->dashboard->getPaneKeyTitleArray();
                    }
                }
            } else {
                if (array_key_exists($populated, $this->dashboard->getHomes())) {
                    $homeId = $this->dashboard->getHomeByName($populated)->getAttribute('homeId');
                    // We have to load dashboards each time the home Id changed
                    $this->dashboard->loadUserDashboardsFromDatabase($homeId);
                    $this->panes = $this->dashboard->getPaneKeyTitleArray();
                }
            }
        }

        if (Url::fromRequest()->getPath() === 'dashboard/remove-dashlet') {
            $this->add(new HtmlElement('h1', null, sprintf(
                t('Please confirm removal of dashlet "%s"'),
                Url::fromRequest()->getParam('dashlet')
            )));
            $this->addElement('submit', 'remove_dashlet', [
                'label' => t('Remove Dashlet'),
            ]);
        } else {
            $submitLabel = t('Add To Dashboard');
            $formTitle = t('Add Dashlet To Dashboard');

            if (Url::fromRequest()->getPath() === 'dashboard/update-dashlet') {
                $submitLabel = t('Update Dashlet');
                $formTitle = t('Edit Dashlet');
            }

            $this->add(Html::tag('h1', $formTitle));
            $this->addElement('hidden', 'org_pane', ['required'     => false]);
            $this->addElement('hidden', 'org_parentId', ['required' => false]);
            $this->addElement('hidden', 'org_dashlet', ['required'  => false]);
            $this->addElement(
                'checkbox',
                'create_new_home',
                [
                    'class'         => 'autosubmit',
                    'disabled'      => empty($dashboardHomes) ?: null,
                    'required'      => false,
                    'label'         => t('New Dashboard Home'),
                    'description'   => t('Check this box if you want to add the dashboard to a new dashboard home.'),
                ]
            );

            $shouldDisable = empty($this->panes) || $this->getPopulatedValue('create_new_home') === 'y';
            if (empty($dashboardHomes) || $this->getPopulatedValue('create_new_home') === 'y') {
                if (empty($dashboardHomes)) {
                    $this->getElement('create_new_home')->addAttributes(['checked' => 'checked']);
                }
                $this->addElement(
                    'text',
                    'home',
                    [
                        'required'      => true,
                        'label'         => t('Dashboard Home'),
                        'description'   => t('Enter a title for the new dashboard'),
                    ]
                );
            } else {
                if (! empty($this->panes)) {
                    $shouldDisable = false;
                }
                $this->addElement(
                    'select',
                    'home',
                    [
                        'class'         => 'autosubmit',
                        'required'      => true,
                        'label'         => t('Dashboard Home'),
                        'multiOptions'  => $dashboardHomes,
                        'description'   => t('Select a home you want to add the pane to'),
                    ]
                );
            }

            $this->addElement(
                'checkbox',
                'create_new_pane',
                [
                    'class'         => 'autosubmit',
                    'disabled'      => $shouldDisable ?: null,
                    'required'      => false,
                    'label'         => t('New Dashboard'),
                    'description'   => t('Check this box if you want to add the dashlet to a new dashboard'),
                ]
            );

            if (empty($this->panes) || $shouldDisable || $this->getPopulatedValue('create_new_pane') === 'y') {
                if ($shouldDisable) {
                    $this->getElement('create_new_pane')->addAttributes(['checked' => 'checked']);
                }
                $this->addElement(
                    'text',
                    'pane',
                    [
                        'required'      => true,
                        'label'         => t('New Dashboard Title'),
                        'description'   => t('Enter a title for the new dashboard'),
                    ]
                );
            } else {
                $this->addElement(
                    'select',
                    'pane',
                    [
                        'required'      => true,
                        'label'         => t('Dashboard'),
                        'multiOptions'  => $this->panes,
                        'description'   => t('Select a dashboard you want to add the dashlet to'),
                    ]
                );
            }

            $this->add(new HtmlElement('hr'));
            if (Url::fromRequest()->getPath() === 'dashboard/update-dashlet') {
                if (Url::fromRequest()->getParam('home') === $this->getValue('home')) {
                    $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
                    $dashlet = $pane->getDashlet(Url::fromRequest()->getParam('dashlet'));

                    if ($dashlet->getDisabled()) {
                        $this->addElement(
                            'checkbox',
                            'enable_dashlet',
                            [
                                'label'         => t('Enable Dashlet'),
                                'value'         => 'n',
                                'description'   => t('Check this box if you want to enable this dashlet.')
                            ]
                        );
                    }
                }
            }
            $this->addElement(
                'textarea',
                'url',
                [
                    'required'      => true,
                    'label'         => t('Url'),
                    'description'   => t(
                        'Enter url to be loaded in the dashlet. You can paste the full URL, including filters.'
                    ),
                ]
            );

            $this->addElement(
                'text',
                'dashlet',
                [
                    'required'      => true,
                    'label'         => t('Dashlet Title'),
                    'description'   => t('Enter a title for the dashlet.'),
                ]
            );
            $this->add(
                new HtmlElement(
                    'div',
                    [
                        'class' => 'control-group form-controls',
                        'style' => 'position: relative;  margin-top: 2em;'
                    ],
                    [
                        Url::fromRequest()->getPath() !== 'dashboard/update-dashlet' ? '' :
                            new HtmlElement(
                                'input',
                                [
                                    'class'         => 'btn-primary',
                                    'type'          => 'submit',
                                    'name'          => 'remove_dashlet',
                                    'value'         => t('Remove Dashlet'),
                                    'formaction'    => (string)Url::fromRequest()->setPath('dashboard/remove-dashlet')
                                ]
                            ),
                        new HtmlElement(
                            'input',
                            [
                                'class' => 'btn-primary',
                                'type'  => 'submit',
                                'name'  => 'submit',
                                'value' => $submitLabel
                            ]
                        ),
                    ]
                )
            );
        }
    }

    public function createDashlet()
    {
        $db = $this->dashboard->getConn();
        if (! array_key_exists($this->getValue('home'), $this->dashboard->getHomes())) {
            $db->insert('dashboard_home', [
                'name'  => $this->getValue('home'),
                'owner' => $this->dashboard->getUser()->getUsername()
            ]);

            $homeId = (int)$db->lastInsertId();
        } else {
            $homeId = (int)$this->dashboard->getHomeByName($this->getValue('home'))->getAttribute('homeId');
        }

        try {
            $this->paneName = $this->getValue('pane');
            $pane = $this->dashboard->getPane($this->paneName);
            $paneId = $pane->getPaneId();

            // If there is already a pane with same name in another home we have to raise
            // an exception so that the new pane can be created in this home
            if ($homeId !== $pane->getParentId()) {
                throw new ProgrammingError('Pane already exist in another home.');
            }
        } catch (ProgrammingError $e) {
            $pane = null;
            $paneName = $this->getValue('pane');
            $paneId = $this->dashboard->getSHA1($this->getValue('home') . $paneName);

            $db->insert('dashboard', [
                'id'        => $paneId,
                'home_id'   => $homeId,
                'owner'     => $this->dashboard->getUser()->getUsername(),
                'name'      => $paneName,
                'label'     => $paneName,
            ]);
        }

        try {
            if (! empty($pane)) {
                $dashlet = array_filter($pane->getDashlets(), function ($dashlet) {
                    if ($dashlet->getName() === $this->getValue('dashlet')) {
                        return $dashlet;
                    }
                });
            } else {
                $dashlet = null;
            }

            if (empty($dashlet)) {
                throw new ProgrammingError('Dashlet does not exist.');
            }

            Notification::warning(t('There already exists a Dashlet with the same name.'));
        } catch (ProgrammingError $err) {
            $dashletId = $this->dashboard->getSHA1(
                $this->getValue('home') . $this->getValue('pane') . $this->getValue('dashlet')
            );
            $db->insert('dashlet', [
                'id'            => $dashletId,
                'dashboard_id'  => $paneId,
                'owner'         => $this->dashboard->getUser()->getUsername(),
                'name'          => $this->getValue('dashlet'),
                'label'         => $this->getValue('dashlet'),
                'url'           => $this->getValue('url')
            ]);

            Notification::success(t('Dashlet created'));
        }
    }

    public function updateDashlet()
    {
        $db = $this->dashboard->getConn();
        // Original home identifier
        $orgHomeId = (int)$this->getValue('org_parentId');
        $defaultHome = $this->dashboard->getHomeByName(Dashboard::DEFAULT_HOME);
        $this->dashboard->loadUserDashboardsFromDatabase($orgHomeId);

        // Original pane and dashlet
        $orgPane = $this->dashboard->getPane($this->getValue('org_pane'));

        try {
            // If the original dashlet cannot be found, it is due to the reason
            // that it is not stored in the DB and therefore it is a system dashlet.
            $orgDashlet = $orgPane->getDashlet($this->getValue('org_dashlet'));
        } catch (ProgrammingError $err) {
            Notification::warning(
                sprintf(t('It is not permitted to move system dashlet: %s'), $this->getValue('org_dashlet'))
            );

            return;
        }

        $homeName = $this->getValue('home');
        if (Url::fromRequest()->getParam('home') === $homeName) {
            $homeId = $orgHomeId;
        } elseif (array_key_exists($homeName, $this->dashboard->getHomes())) {
            $homeId = $this->dashboard->getHomeByName($homeName)->getAttribute('homeId');
        } else {
            $db->insert('dashboard_home', [
                'name'  => $this->getValue('home'),
                'owner' => $this->dashboard->getUser()->getUsername()
            ]);

            $homeId = $db->lastInsertId();
        }

        $this->dashboard->loadUserDashboardsFromDatabase($homeId);
        if ($this->dashboard->hasPane($this->getValue('pane'))) {
            $newPane = $this->dashboard->getPane($this->getValue('pane'));
            $paneId = $newPane->getPaneId();

            $db->update('dashboard', ['home_id' => $homeId], ['id = ?' => $newPane->getPaneId()]);
        } else {
            $paneId = $this->dashboard->getSHA1($this->getValue('home') . $this->getValue('pane'));
            $db->insert('dashboard', [
                'id'        => $paneId,
                'home_id'   => $homeId,
                'name'      => $this->getValue('pane'),
                'owner'     => $this->dashboard->getUser()->getUsername(),
                'label'     => $this->getValue('pane'),
            ]);
        }

        // Whether the original home id matches the default home id
        if ($orgDashlet->getPane()->getParentId() === $defaultHome->getAttribute('homeId')) {
            $dashletUrl = null;
            $dashletLabel = null;
            $dashletDisabled = $orgDashlet->getDisabled();

            if ($orgDashlet->getTitle() !== $this->getValue('dashlet')) {
                $dashletLabel = $this->getValue('dashlet');
            } elseif ($orgDashlet->getUrl() !== $this->getValue('url')) {
                $dashletUrl = $this->getValue('url');
            }

            if ($this->getPopulatedValue('enable_dashlet') === 'y') {
                $dashletDisabled = false;
            }

            $dashletUpdated = false;
            if (! $orgDashlet->isUserWidget()) {
                $dashletUpdated = true;
                $db->insert('dashlet_override', [
                    'dashlet_id'    => $orgDashlet->getDashletId(),
                    'dashboard_id'  => $orgDashlet->getPane()->getPaneId(),
                    'owner'         => $this->dashboard->getUser()->getUsername(),
                    'label'         => $dashletLabel,
                    'url'           => $dashletUrl,
                    'disabled'      => (int)$dashletDisabled
                ]);
            } elseif ($orgDashlet->isOverridden()) {
                $dashletUpdated = true;

                $db->update('dashlet_override', [
                    'label'     => $dashletLabel,
                    'url'       => $dashletUrl,
                    'disabled'  => (int)$dashletDisabled
                ], ['dashlet_id = ?'    => $orgDashlet->getDashletId()]);
            }

            if ($dashletUpdated) {
                Notification::success(t('Dashlet updated!'));
                return;
            }
        }

        $db->update('dashlet', [
            'dashboard_id'  => $paneId,
            'owner'         => $this->dashboard->getUser()->getUsername(),
            'name'          => $orgDashlet->getName(),
            'label'         => $this->getValue('dashlet'),
            'url'           => $this->getValue('url')
        ], ['dashlet.id = ?'  => $orgDashlet->getDashletId()]);

        Notification::success(t('Dashlet updated'));
    }

    public function onSuccess()
    {
        if (Url::fromRequest()->getPath() === 'dashboard/new-dashlet') {
            $this->createDashlet();
        } else {
            if ($this->getPopulatedValue('remove_dashlet')) {
                $dashlet = Url::fromRequest()->getParam('dashlet');
                $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
                $pane->removeDashlet($dashlet);

                Notification::success(t('Dashlet has been removed from') . ' ' . $pane->getTitle());
            } else {
                $this->updateDashlet();
            }
        }
    }

    /**
     * @param \Icinga\Web\Widget\Dashboard $dashboard
     */
    public function setDashboard(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @param Dashlet $dashlet
     */
    public function load(Dashlet $dashlet)
    {
        $this->populate(array(
            'pane'          => $dashlet->getPane()->getName(),
            'org_pane'      => $dashlet->getPane()->getName(),
            'org_parentId'  => $dashlet->getPane()->getParentId(),
            'dashlet'       => $dashlet->getTitle(),
            'org_dashlet'   => $dashlet->getName(),
            'url'           => $dashlet->getUrl()->getRelativeUrl()
        ));
    }
}
