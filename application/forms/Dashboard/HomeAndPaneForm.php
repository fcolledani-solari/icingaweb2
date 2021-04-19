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

    /**
     * RenamePaneForm constructor.
     *
     * @param Dashboard $dashboard
     */
    public function __construct(Dashboard $dashboard)
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
        $removeHome = 'dashboard/remove-home';
        $renamePane = 'dashboard/rename-pane';
        $removePane = 'dashboard/remove-pane';

        $home = Url::fromRequest()->getParam('home');
        $populated = $this->getPopulatedValue('home');
        $dashboardHomes = $this->dashboard->getHomeKeyNameArray();
        if ($populated === null) {
            $dashboardHomes = $this->dashboard->changeElementPos($dashboardHomes, $home);
        }

        $description    = t('Edit the current home name');
        $btnUpdateLabel = t('Update Home');
        $btnRemoveLabel = t('Remove Home');
        $formaction     = (string)Url::fromRequest()->setPath($removeHome);

        if ($renamePane === Url::fromRequest()->getPath()) {
            $description    = t('Edit the current pane name');
            $btnUpdateLabel = t('Update Pane');
            $btnRemoveLabel = t('Remove Pane');
            $formaction     = (string)Url::fromRequest()->setPath($removePane);

            $pane = $this->dashboard->getPane(Url::fromRequest()->getParam('pane'));
            if ($pane->getDisabled()) {
                $this->addElement(
                    'checkbox',
                    'enable_pane',
                    [
                        'label'         => t('Enable Pane'),
                        'value'         => 'n',
                        'description'   => t('Check this box if you want to enable this pane.')
                    ]
                );
            }

            $this->addElement(
                'select',
                'home',
                [
                    'required'      => true,
                    'label'         => t('Move to home'),
                    'multiOptions'  => $dashboardHomes,
                    'description'   => t('Select a dashboard home you want to move the dashboard to'),
                ]
            );
        }

        if ($removePane == Url::fromRequest()->getPath()) {
            $btnRemoveLabel = t('Remove Pane');
            $formaction = (string)Url::fromRequest()->setPath($removePane);
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

        if ($renamePane === Url::fromRequest()->getPath()) {
            $this->addElement(
                'text',
                'title',
                [
                    'required'      => true,
                    'label'         => t('Title'),
                    'description'   => t('Edit the current pane title.')
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
                    $removeHome === Url::fromRequest()->getPath() || $removePane === Url::fromRequest()->getPath() ?'':
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
        $orgHome = Url::fromRequest()->getParam('home');
        $home = $this->dashboard->getHomeByName($orgHome);

        if ($requestPath === 'dashboard/rename-pane' || $requestPath === 'dashboard/remove-pane') {
            // Update the given pane
            $paneName = Url::fromRequest()->getParam('pane');
            $pane = $this->dashboard->getPane($paneName);

            if ($this->getPopulatedValue('btn_update')) {
                $newHome = $this->getPopulatedValue('home');
                $homeId = $home->getAttribute('homeId');

                if (! $pane->getOwner() && $orgHome !== $newHome) {
                    Notification::warning(sprintf(
                        t('It is not permitted to move system dashboard: %s'),
                        $pane->getTitle()
                    ));

                    return;
                }

                if ($orgHome !== $newHome) {
                    $homeId = $this->dashboard->getHomeByName($newHome)->getAttribute('homeId');
                }

                $paneDisabled = $pane->getDisabled();
                if ($this->getPopulatedValue('enable_pane') === 'y') {
                    $paneDisabled = false;

                    $this->dashboard->getConn()->update('dashlet_override', ['disabled' => (int)$paneDisabled], [
                        'dashboard_id = ?'    => $pane->getPaneId()
                    ]);
                }

                $this->dashboard->getConn()->update('dashboard', [
                    'home_id'   => $homeId,
                    'name'      => $this->getValue('name'),
                    'label'     => $this->getPopulatedValue('title'),
                    'disabled'  => (int)$paneDisabled
                ], ['id = ?' => $pane->getPaneId()]);

                Notification::success(
                    sprintf(t('Pane "%s" successfully renamed to "%s".'), $paneName, $this->getValue('name'))
                );
            } else {
                // Remove the given pane and it's dashlets
                $pane->removeDashlets();
                $this->dashboard->removePane($pane->getName());

                Notification::success(t('Dashboard has been removed') . ': ' . $pane->getTitle());
            }
        } else {
            // Update the given dashboard home
            if ($this->getPopulatedValue('btn_update')) {
                if (Dashboard::DEFAULT_HOME === $home->getName()) {
                    Notification::warning(sprintf(t('It is not permitted to edit default home: %s'), $home->getName()));
                    return;
                }

                $this->dashboard->getConn()->update('dashboard_home', [
                    'name'   => $this->getValue('name')
                ], ['id = ?' => $home->getAttribute('homeId')]);

                Notification::success(
                    sprintf(t('Home "%s" successfully renamed to "%s".'), $home->getName(), $this->getValue('name'))
                );
            } else {
                // Remove the given home with it's panes and dashlets
                $this->dashboard->removeHome($orgHome);

                if ($orgHome !== Dashboard::DEFAULT_HOME) {
                    Notification::success(sprintf(t('Dashboard home has been removed: %s'), $orgHome));
                } else {
                    Notification::warning(
                        sprintf(t('It is not permitted to remove default home: %s'), Dashboard::DEFAULT_HOME)
                    );
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
