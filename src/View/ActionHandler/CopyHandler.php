<?php

/**
 * This file is part of contao-community-alliance/dc-general-contao-frontend.
 *
 * (c) 2015-2023 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general-contao-frontend
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2023 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\ActionHandler;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminatorAwareTrait;
use ContaoCommunityAlliance\DcGeneral\Controller\ControllerInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Exception\NotCreatableException;
use ContaoCommunityAlliance\DcGeneral\InputProviderInterface;
use ContaoCommunityAlliance\UrlBuilder\UrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * This class handles the copy actions in the frontend.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CopyHandler
{
    use RequestScopeDeterminatorAwareTrait;

    /**
     * The current request stack.
     *
     * @var RequestStack
     */
    private RequestStack $requestStack;

    /**
     * CopyHandler constructor.
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
     * @throws RedirectResponseException To redirect to the edit mask with cloned model.
     * @throws DcGeneralRuntimeException When the DataContainer is not creatable.
     */
    public function handleEvent(ActionEvent $event): void
    {
        if (
            null === ($scopeDeterminator = $this->scopeDeterminator)
            || !$scopeDeterminator->currentScopeIsFrontend()
        ) {
            return;
        }

        $environment = $event->getEnvironment();
        $action      = $event->getAction();

        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('copy' !== $action->getName()) {
            return;
        }

        // Only run when no response given yet.
        if ('' !== $event->getResponse()) {
            return;
        }

        $this->process($environment);
    }

    /**
     * Handle the action.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @return void
     *
     * @throws RedirectResponseException To redirect to the edit mask with cloned model.
     * @throws NotCreatableException     When the DataContainer is not creatable.
     * @throws PageNotFoundException     When model not found.
     */
    public function process(EnvironmentInterface $environment): void
    {
        $dispatcher = $environment->getEventDispatcher();
        assert($dispatcher instanceof EventDispatcherInterface);

        $definition = $environment->getDataDefinition();
        assert($definition instanceof ContainerInterface);

        $basicDefinition = $definition->getBasicDefinition();

        $currentRequest = $this->requestStack->getCurrentRequest() ;
        assert($currentRequest instanceof Request);

        $currentUrl = $currentRequest->getUri();

        if (!$basicDefinition->isCreatable()) {
            throw new NotCreatableException('DataContainer ' . $definition->getName() . ' is not creatable');
        }

        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_HIERARCHICAL === $basicDefinition->getMode()) {
            return;
        }

        $inputProvider = $environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $modelId = ModelId::fromSerialized((string) $inputProvider->getParameter('source'));

        $dataProvider = $environment->getDataProvider();
        assert($dataProvider instanceof DataProviderInterface);

        $model = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        if (null === $model) {
            throw new PageNotFoundException('Model not found: ' . $modelId->getSerialized());
        }

        $controller = $environment->getController();
        assert($controller instanceof ControllerInterface);

        $copyModel = $controller->createClonedModel($model);

        // Dispatch pre duplicate event.
        $copyEvent = new PreDuplicateModelEvent($environment, $copyModel, $model);
        $dispatcher->dispatch($copyEvent, $copyEvent::NAME);

        // Save the copy.
        $provider = $environment->getDataProvider($copyModel->getProviderName());
        assert($provider instanceof DataProviderInterface);

        $provider->save($copyModel);

        // Dispatch post duplicate event.
        $copyEvent = new PostDuplicateModelEvent($environment, $copyModel, $model);
        $dispatcher->dispatch($copyEvent, $copyEvent::NAME);

        // Redirect to the edit mask of the cloned model
        throw new RedirectResponseException(
            UrlBuilder::fromUrl($currentUrl)
                ->setQueryParameter('act', 'edit')
                ->setQueryParameter('id', ModelId::fromModel($copyModel)->getSerialized())
                ->unsetQueryParameter('source')
                ->getUrl()
        );
    }
}
