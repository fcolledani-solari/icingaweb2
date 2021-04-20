<?php
/* Icinga Web 2 | (c) 2021 Icinga GmbH | GPLv2+ */

namespace Icinga\Web\Navigation\Renderer;

use Exception;
use Icinga\Application\Hook;
use Icinga\Exception\IcingaException;

class HealthNavigationRenderer extends BadgeNavigationItemRenderer
{
    public function getCount()
    {
        $count = 0;
        $title = null;
        $worstState = null;
        foreach (Hook::all('health') as $hook) {
            /** @var Hook\HealthHook $hook */

            try {
                $hook->collectHealthInfo();
                $state = $hook->getState();
                $message = $hook->getMessage();
            } catch (Exception $e) {
                $state = Hook\HealthHook::STATE_UNKNOWN;
                $message = IcingaException::describe($e);
            }

            if ($worstState === null || $state > $worstState) {
                $worstState = $state;
                $title = $message;
                $count = 1;
            } elseif ($worstState === $state) {
                $count++;
            }
        }

        switch ($worstState) {
            case Hook\HealthHook::STATE_OK:
                $count = 0;
                break;
            case Hook\HealthHook::STATE_WARNING:
                $this->state = self::STATE_WARNING;
                break;
            case Hook\HealthHook::STATE_CRITICAL:
                $this->state = self::STATE_CRITICAL;
                break;
            case Hook\HealthHook::STATE_UNKNOWN:
                $this->state = self::STATE_UNKNOWN;
                break;
        }

        $this->title = $title;

        return $count;
    }
}
