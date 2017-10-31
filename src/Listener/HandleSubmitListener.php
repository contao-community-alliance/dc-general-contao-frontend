<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  2015 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Listener;

use Contao\Environment;
use ContaoCommunityAlliance\Contao\Bindings\ContaoEvents;
use ContaoCommunityAlliance\Contao\Bindings\Events\Controller\RedirectEvent;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\HandleSubmitEvent;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\UrlBuilder\UrlBuilder;

/**
 * This class handles the submit buttons in the frontend for "save" and "save and create".
 */
class HandleSubmitListener
{

    /**
     * Handle the event.
     *
     * @param HandleSubmitEvent $event The event.
     *
     * @return void
     */
    public function handleEvent(HandleSubmitEvent $event)
    {
        $dispatcher = func_get_arg(2);
        $currentUrl = Environment::get('uri');

        switch ($event->getButtonName()) {
            case 'save':
                // Could be a create action, always redirect to current page with edit action and id properly set.
                $url = UrlBuilder::fromUrl($currentUrl)
                    ->setQueryParameter('act', 'edit')
                    ->setQueryParameter('id', ModelId::fromModel($event->getModel())->getSerialized());

                $dispatcher->dispatch(ContaoEvents::CONTROLLER_REDIRECT, new RedirectEvent($url->getUrl()));
                break;
            case 'saveNcreate':
                // We want to create a new model, set create action and pass the current id as "after" to keep sorting.
                $after = ModelId::fromModel($event->getModel());
                $url   = UrlBuilder::fromUrl($currentUrl)
                    ->unsetQueryParameter('id')
                    ->setQueryParameter('act', 'create')
                    ->setQueryParameter('after', $after->getSerialized());

                $dispatcher->dispatch(ContaoEvents::CONTROLLER_REDIRECT, new RedirectEvent($url->getUrl()));
                break;
            default:
                // Do nothing.
        }
    }
}
