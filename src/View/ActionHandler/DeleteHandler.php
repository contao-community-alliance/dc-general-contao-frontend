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
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2018 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\RedirectResponseException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ActionHandler\AbstractRequestScopeDeterminatorHandler;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostDeleteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreDeleteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * This class handles the edit actions in the frontend.
 */
class DeleteHandler extends AbstractRequestScopeDeterminatorHandler
{

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * CopyHandler constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request mode determinator.
     *
     * @param RequestStack             $requestStack      The current request stack.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator, RequestStack $requestStack)
    {
        parent::__construct($scopeDeterminator);

        $this->requestStack = $requestStack;
    }

    /**
     * Handle the event to process the action.
     *
     * @param ActionEvent $event The action event
     *
     * @return void
     *
     * @throws RedirectResponseException After successful delete.
     * @throws DcGeneralRuntimeException When the DataContainer is not deletable.
     */
    public function handleEvent(ActionEvent $event)
    {
        if (!$this->scopeDeterminator->currentScopeIsFrontend()) {
            return;
        }

        $action = $event->getAction();
        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('delete' !== $action->getName()) {
            return;
        }

        // Only run when no response given yet.
        if (null !== $event->getResponse()) {
            return;
        }

        $this->process($event->getEnvironment());
    }

    /**
     * Handle the action.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @return void
     *
     * @throws RedirectResponseException After successful delete.
     * @throws DcGeneralRuntimeException When the DataContainer is not deletable.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isDeletable()) {
            throw new DcGeneralRuntimeException('DataContainer '.$definition->getName().' is not deletable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return;
        }
        $modelId = ModelId::fromSerialized($environment->getInputProvider()->getParameter('id'));

        $dataProvider = $environment->getDataProvider();
        $model        = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        // Trigger event before the model will be deleted.
        $event = new PreDeleteModelEvent($environment, $model);
        $environment->getEventDispatcher()->dispatch($event::NAME, $event);

        $dataProvider->delete($model);

        // Trigger event after the model is deleted.
        $event = new PostDeleteModelEvent($environment, $model);
        $environment->getEventDispatcher()->dispatch($event::NAME, $event);

        throw new RedirectResponseException($this->requestStack->getCurrentRequest()->headers->get('referer'));
    }
}
