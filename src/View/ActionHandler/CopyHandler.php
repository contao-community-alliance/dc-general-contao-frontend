<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2017 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\Environment;
use ContaoCommunityAlliance\Contao\Bindings\ContaoEvents;
use ContaoCommunityAlliance\Contao\Bindings\Events\Controller\RedirectEvent;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Event\PostDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\View\ActionHandler\AbstractHandler;
use ContaoCommunityAlliance\UrlBuilder\UrlBuilder;

/**
 * This class handles the copy actions in the frontend.
 */
class CopyHandler extends AbstractHandler
{
    /**
     * Handle the action.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException When the definition is not creatable.
     */
    public function process()
    {
        $environment     = $this->getEnvironment();
        $dispatcher      = $environment->getEventDispatcher();
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();
        $currentUrl      = Environment::get('uri');

        if (!$basicDefinition->isCreatable()) {
            throw new DcGeneralRuntimeException('DataContainer ' . $definition->getName() . ' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return;
        }
        $modelId = ModelId::fromSerialized($environment->getInputProvider()->getParameter('source'));

        $dataProvider = $environment->getDataProvider();
        $model        = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));
        $copyModel    = $environment->getController()->createClonedModel($model);

        // Dispatch pre duplicate event.
        $copyEvent = new PreDuplicateModelEvent($environment, $copyModel, $model);
        $dispatcher->dispatch($copyEvent::NAME, $copyEvent);

        // Save the copy.
        $provider = $environment->getDataProvider($copyModel->getProviderName());
        $provider->save($copyModel);

        // Dispatch post duplicate event.
        $copyEvent = new PostDuplicateModelEvent($environment, $copyModel, $model);
        $dispatcher->dispatch($copyEvent::NAME, $copyEvent);

        // Redirect to the edit mask of the cloned model
        $url = UrlBuilder::fromUrl($currentUrl)
            ->setQueryParameter('act', 'edit')
            ->setQueryParameter('id', ModelId::fromModel($copyModel)->getSerialized())
            ->unsetQueryParameter('source');
        $dispatcher->dispatch(ContaoEvents::CONTROLLER_REDIRECT, new RedirectEvent($url->getUrl()));
    }
}
