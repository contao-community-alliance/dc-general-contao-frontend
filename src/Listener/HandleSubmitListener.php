<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2018 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2018 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Listener;

use Contao\CoreBundle\Exception\RedirectResponseException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\HandleSubmitEvent;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\UrlBuilder\UrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * This class handles the submit buttons in the frontend for "save" and "save and create".
 */
class HandleSubmitListener
{

    /**
     * The request scope determinator.
     *
     * @var RequestScopeDeterminator
     */
    private $scopeDeterminator;

    /**
     * The request stack.
     *
     * @var RequestStack
     */
    private $requestStack;

    /**
     * HandleSubmitListener constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request scope determinator.
     * @param RequestStack             $requestStack      The request stack.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator, RequestStack $requestStack)
    {
        $this->scopeDeterminator = $scopeDeterminator;
        $this->requestStack      = $requestStack;
    }

    /**
     * Handle the event.
     *
     * @param HandleSubmitEvent $event The event.
     *
     * @return void
     *
     * @throws RedirectResponseException To redirect to the proper edit mask.
     */
    public function handleEvent(HandleSubmitEvent $event)
    {
        // Only run in the frontend
        if (false === $this->scopeDeterminator->currentScopeIsFrontend()) {
            return;
        }

        $currentUrl = $this->requestStack->getCurrentRequest()->getUri();

        switch ($event->getButtonName()) {
            case 'save':
                // Could be a create action, always redirect to current page with edit action and id properly set.
                $url = UrlBuilder::fromUrl($currentUrl)
                    ->setQueryParameter('act', 'edit')
                    ->setQueryParameter('id', ModelId::fromModel($event->getModel())->getSerialized());

                throw new RedirectResponseException($url->getUrl());
            case 'saveNcreate':
                // We want to create a new model, set create action and pass the current id as "after" to keep sorting.
                $after = ModelId::fromModel($event->getModel());
                $url   = UrlBuilder::fromUrl($currentUrl)
                    ->unsetQueryParameter('id')
                    ->setQueryParameter('act', 'create')
                    ->setQueryParameter('after', $after->getSerialized());

                throw new RedirectResponseException($url->getUrl());
            default:
                // Do nothing.
        }
    }
}
