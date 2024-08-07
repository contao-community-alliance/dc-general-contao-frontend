<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2024 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2024 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminatorAwareTrait;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostDeleteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreDeleteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Exception\NotDeletableException;
use ContaoCommunityAlliance\DcGeneral\InputProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * This class handles the actions of edit in the frontend.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DeleteHandler
{
    use RequestScopeDeterminatorAwareTrait;

    /**
     * The current request stack.
     *
     * @var RequestStack
     */
    private RequestStack $requestStack;

    /**
     * DeleteHandler constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request mode determinator.
     * @param RequestStack             $requestStack      The current request stack.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator, RequestStack $requestStack)
    {
        $this->setScopeDeterminator($scopeDeterminator);

        $this->requestStack = $requestStack;
    }

    /**
     * Handle the event to process the action.
     *
     * @param ActionEvent $event The action event.
     *
     * @return void
     *
     * @throws RedirectResponseException After successful delete.
     * @throws DcGeneralRuntimeException When the DataContainer is not deletable.
     */
    public function handleEvent(ActionEvent $event): void
    {
        if (
            null === ($scopeDeterminator = $this->scopeDeterminator)
            || !$scopeDeterminator->currentScopeIsFrontend()
        ) {
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
     * @throws NotDeletableException     When the DataContainer is not deletable.
     * @throws PageNotFoundException     When model not found.
     */
    public function process(EnvironmentInterface $environment): void
    {
        $definition = $environment->getDataDefinition();
        assert($definition instanceof ContainerInterface);

        $basicDefinition = $definition->getBasicDefinition();

        if (!$basicDefinition->isDeletable()) {
            throw new NotDeletableException('DataContainer ' . $definition->getName() . ' is not deletable');
        }

        $inputProvider = $environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $modelId = ModelId::fromSerialized($inputProvider->getParameter('id'));

        $dataProvider = $environment->getDataProvider();
        assert($dataProvider instanceof DataProviderInterface);

        $model = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        if (null === $model) {
            throw new PageNotFoundException('Model not found: ' . $modelId->getSerialized());
        }

        // Trigger event before the model will be deleted.
        $event = new PreDeleteModelEvent($environment, $model);
        $eventDispatcher = $environment->getEventDispatcher();
        assert($eventDispatcher instanceof EventDispatcherInterface);

        $eventDispatcher->dispatch($event, $event::NAME);

        $dataProvider->delete($model);

        // Trigger event after the model is deleted.
        $event = new PostDeleteModelEvent($environment, $model);
        $eventDispatcher->dispatch($event, $event::NAME);

        $currentRequest = $this->requestStack->getCurrentRequest();
        assert($currentRequest instanceof Request);

        throw new RedirectResponseException($currentRequest->headers->get('referer') ?? '');
    }
}
