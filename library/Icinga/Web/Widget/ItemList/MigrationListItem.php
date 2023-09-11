<?php

/* Icinga Web 2 | (c) 2023 Icinga GmbH | GPLv2+ */

namespace Icinga\Web\Widget\ItemList;

use Icinga\Application\Hook\Common\DbMigration;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\HtmlString;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;

class MigrationListItem extends BaseListItem
{
    use Translation;

    /** @var DbMigration Just for type hint */
    protected $item;

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        if ($this->item->getLastState()) {
            $visual->getAttributes()->add('class', 'upgrade-failed');
            $visual->addHtml(new Icon('circle-xmark'));
        }
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $scriptPath = $this->item->getScriptPath();
        /** @var string $parentDirs */
        $parentDirs = substr($scriptPath, (int) strpos($scriptPath, 'schema'));
        $parentDirs = substr($parentDirs, 0, strrpos($parentDirs, '/') + 1);

        $title->addHtml(
            new HtmlElement('span', null, Text::create($parentDirs)),
            new HtmlElement('strong', null, Text::create($this->item->getVersion() . '.sql'))
        );

        if ($this->item->getLastState()) {
            $title->addHtml(
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'upgrade-failed']),
                    Text::create($this->translate('Upgrade failed'))
                )
            );
        }
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->addHtml($this->createTitle());
    }

    protected function assembleCaption(BaseHtmlElement $caption): void
    {
        if ($this->item->getDescription()) {
            $caption->addHtml(Text::create($this->item->getDescription()));
        } else {
            $caption->getAttributes()->add('class', 'empty-state');
            $caption->addHtml(Text::create($this->translate('No description provided.')));
        }
    }

    protected function assembleFooter(BaseHtmlElement $footer): void
    {
        if ($this->item->getLastState()) {
            $footer->addHtml(
                new HtmlElement(
                    'section',
                    Attributes::create(['class' => 'caption']),
                    new HtmlElement('pre', null, new HtmlString(Html::escape($this->item->getLastState())))
                )
            );
        }
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->addHtml($this->createHeader(), $this->createCaption());
    }
}
