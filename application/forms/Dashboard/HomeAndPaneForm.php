<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Navigation\NavigationItem;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use ipl\Html\HtmlElement;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class HomeAndPaneForm extends CompatForm
{
    /** @var Dashboard */
    private $dashboard;

    private $navigation;

    /**
     * RenamePaneForm constructor.
     *
     * @param $dashboard
     */
    public function __construct($dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @inheritdoc
     *
     * @return bool
     */
    public function hasBeenSubmitted()
    {
        return $this->hasBeenSent()
            && ($this->getPopulatedValue('btn_remove')
                || $this->getPopulatedValue('btn_update'));
    }

    public function assemble()
    {
        $dashboardHomes = [];
        $home = Url::fromRequest()->getParam('home');
        $populated = $this->getPopulatedValue('home');
        if ($populated === null) {
            $dashboardHomes[$home] = $home;
        }

        foreach ($this->dashboard->getHomes() as $name => $homeItem) {
            $this->navigation[$name] = $homeItem;
            if (! array_key_exists($name, $dashboardHomes)) {
                $dashboardHomes[$name] = $homeItem->getName();
            }
        }

        $description    = t('Edit the current home name');
        $btnUpdateLabel = t('Update Home');
        $btnRemoveLabel = t('Remove Home');
        $formaction     = (string)Url::fromRequest()->setPath('dashboard/remove-home');

        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $description    = t('Edit the current pane name');
            $btnUpdateLabel = t('Update Pane');
            $btnRemoveLabel = t('Remove Pane');
            $formaction     = (string)Url::fromRequest()->setPath('dashboard/remove-pane');

            $this->addElement(
                'select',
                'home',
                [
                    'required'      => true,
                    'label'         => t('Move to home'),
                    'multiOptions'  => $dashboardHomes,
                    'description'   => t('Select a dashboard home you want to move the dashboard to.'),
                ]
            );
        }

        $this->addElement(
            'text',
            'name',
            [
                'required'      => true,
                'label'         => t('Name'),
                'description'   => $description
            ]
        );

        if (Url::fromRequest()->getPath() === 'dashboard/rename-pane') {
            $this->addElement(
                'text',
                'title',
                [
                    'required'      => true,
                    'label'         => t('Title'),
                    'description'   => t('Edit the current pane title')
                ]
            );
        }

        $this->add(
            new HtmlElement(
                'div',
                [
                    'class' => 'control-group form-controls',
                    'style' => 'position: relative; margin-right: 1em; margin-top: 2em;'
                ],
                [
                    new HtmlElement(
                        'input',
                        [
                            'class'             => 'btn-primary',
                            'type'              => 'submit',
                            'name'              => 'btn_remove',
                            'data-base-target'  => '_main',
                            'value'             => $btnRemoveLabel,
                            'formaction'        => $formaction
                        ]
                    ),
                    new HtmlElement(
                        'input',
                        [
                            'class' => 'btn-primary',
                            'type'  => 'submit',
                            'name'  => 'btn_update',
                            'value' => $btnUpdateLabel
                        ]
                    )
                ]
            )
        );
    }

    public function onSuccess()
    {
        $requestPath = Url::fromRequest()->getPath();
        if ($requestPath === 'dashboard/rename-pane' || $requestPath === 'dashboard/remove-pane') {
            // Update the given pane
            if ($this->getPopulatedValue('btn_update')) {
                $orgParent = Url::fromRequest()->getParam('home');
                $newParent = $this->getPopulatedValue('home');
                $parent = $this->navigation[$orgParent]->getAttribute('homeId');
                if ($orgParent !== $newParent) {
                    $parent = $this->navigation[$newParent]->getAttribute('homeId');
                }

                $paneName = Url::fromRequest()->getParam('pane');
                $newName  = $this->getValue('name');

                $pane = $this->dashboard->getPane($paneName);
                $this->dashboard->getConn()->update('dashboard', [
                    'home_id'   => $parent,
                    'name'      => $newName,
                    'label'     => $this->getPopulatedValue('title')
                ], ['id = ?' => $pane->getPaneId()]);

                Notification::success(
                    sprintf(t('Pane "%s" successfully renamed to "%s"'), $paneName, $newName)
                );
            } else {
                // Remove the given pane and it's dashlets
                $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
                $pane->removeDashlets();
                $this->dashboard->removePane($pane->getTitle());

                Notification::success(t('Dashboard has been removed') . ': ' . $pane->getTitle());            }
        } else {
            // Update the given dashboard home
            if ($this->getPopulatedValue('btn_update')) {
                $homes = $this->dashboard->getHomes();
                $home = $homes[Url::fromRequest()->getParam('home')];

                $this->dashboard->getConn()->update('dashboard_home', [
                    'name'   => $this->getValue('name')
                ], ['id = ?' => $home->getAttribute('homeId')]);

                Notification::success(
                    sprintf(t('Home "%s" successfully renamed to "%s"'), $home->getName(), $this->getValue('name'))
                );
            } else {
                // Remove the given home with it's panes and dashlets
                $home = Url::fromRequest()->getParam('home');
                if ($home !== Dashboard::DEFAULT_HOME) {
                    $this->dashboard->removeHome($home);

                    Notification::success(t('Dashboard home has been removed') . ': ' . $home);
                } else {
                    Notification::warning(sprintf(t('%s home can\'t be deleted.'), Dashboard::DEFAULT_HOME));
                }
            }
        }
    }

    /**
     * @param Dashboard\Pane|NavigationItem  $paneOrHome
     */
    public function load($paneOrHome)
    {
        if (Url::fromRequest()->getPath() !== 'dashboard/rename-pane') {
            $this->populate([
                'name'  => $paneOrHome->getName(),
            ]);
        } else {
            $this->populate([
                'name'  => $paneOrHome->getName(),
                'title' => $paneOrHome->getTitle()
            ]);
        }
    }
}
