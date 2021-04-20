<?php
/* Icinga Web 2 | (c) 2021 Icinga GmbH | GPLv2+ */

namespace Icinga\Web\View;

use Exception;
use Icinga\Application\Hook;
use Icinga\Application\Hook\HealthHook;
use Icinga\Application\Logger;
use Icinga\Exception\IcingaException;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class AppHealth extends BaseHtmlElement
{
    use BaseTarget;

    protected $tag = 'ul';

    protected $defaultAttributes = ['class' => 'app-health'];

    protected function assemble()
    {
        $subGroups = [];
        $worstState = null;

        foreach (Hook::all('health') as $hook) {
            /** @var HealthHook $hook */
            $moduleName = $hook->getModuleName();

            if (! isset($subGroups[$moduleName])) {
                $subGroup = new HtmlElement('ul', ['class' => 'module-health']);
                $subGroups[$moduleName] = $subGroup;
            } else {
                $subGroup = $subGroups[$moduleName];
            }

            try {
                $hook->collectHealthInfo();
                $state = $hook->getState();
                $message = $hook->getMessage();
                $url = $hook->getUrl();
            } catch (Exception $e) {
                Logger::error('Failed to collect health information: %s', $e);

                $state = HealthHook::STATE_UNKNOWN;
                $message = IcingaException::describe($e);
                $url = null;
            }

            if ($worstState === null || $state > $worstState) {
                $worstState = $state;
            }

            $subGroup->add(new HtmlElement('li', null, [
                new HtmlElement('i', ['class' => ['state', $this->getStateClass($state)]]),
                new HtmlElement('p', null, $message),
                $url === null ? null : new Link(t('Show details'), $url)
            ]));
        }

        if (empty($subGroups)) {
            $this->add(Text::create(t('No health information available')));
            return;
        }

        list($icon, $mainTitle, $subTitle) = $this->getStateIntro($worstState);
        $this->add(new HtmlElement('div', ['class' => 'health-intro'], [
            new HtmlElement('strong', null, [
                new Icon($icon, ['class' => $this->getStateClass($worstState)]),
                $mainTitle
            ]),
            ' ',
            new HtmlElement('em', null, $subTitle)
        ]));

        foreach ($subGroups as $name => $subGroup) {
            $this->add(new HtmlElement('li', null, [
                new HtmlElement('h2', null, [
                    $name,
                    ' ',
                    new HtmlElement('em', null, t('Module'))
                ]),
                $subGroup
            ]));
        }

        $this->setBaseTarget('_next');
    }

    protected function getStateClass($state)
    {
        if ($state === null) {
            $state = HealthHook::STATE_UNKNOWN;
        }

        switch ($state) {
            case HealthHook::STATE_OK:
                return 'state-ok';
            case HealthHook::STATE_WARNING:
                return 'state-warning';
            case HealthHook::STATE_CRITICAL:
                return 'state-critical';
            case HealthHook::STATE_UNKNOWN:
                return 'state-unknown';
        }
    }

    protected function getStateIntro($state)
    {
        if ($state === null) {
            $state = HealthHook::STATE_UNKNOWN;
        }

        switch ($state) {
            case HealthHook::STATE_OK:
                $icon = 'thumbs-up';
                $mainTitle = t('All is lookin\' good!');
                $subTitle = t('You should\'t encounter too severe problems...');
                break;
            case HealthHook::STATE_WARNING:
                $icon = 'exclamation-triangle';
                $mainTitle = t('Somethin\'s about to break!');
                $subTitle = t('It may or may not. Hope for the best!');
                break;
            case HealthHook::STATE_CRITICAL:
                $icon = 'times-circle';
                $mainTitle = t('Somethin\'s not right!');
                $subTitle = t('Your environment is slowly burning down...');
                break;
            case HealthHook::STATE_UNKNOWN:
                $icon = 'question-circle';
                $mainTitle = t('Somethin\'s... to be determined?');
                $subTitle = t('The cat knows the answer!');
        }

        return [$icon, $mainTitle, $subTitle];
    }
}
