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
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View\EditMask;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostCreateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreCreateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use MetaModels\IFactory;

/**
 * This class handles the "create variant" actions in the frontend.
 */
class CreateVariantHandler extends AbstractRequestScopeDeterminatorHandler
{

    /**
     * The MetaModels factory.
     *
     * @var IFactory
     */
    private $factory;

    /**
     * CopyHandler constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request mode determinator.
     *
     * @param IFactory                 $factory           The MetaModels factory.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator, IFactory $factory)
    {
        parent::__construct($scopeDeterminator);

        $this->factory = $factory;
    }

    /**
     * Handle the event to process the action.
     *
     * @param ActionEvent $event The action event.
     *
     * @throws DcGeneralInvalidArgumentException If an unknown property is encountered in the palette.
     * @throws RedirectResponseException         To redirect to the edit mask with cloned model.
     * @throws DcGeneralRuntimeException         When the DataContainer is not creatable.
     *
     * @return void
     */
    public function handleEvent(ActionEvent $event)
    {
        if (!$this->scopeDeterminator->currentScopeIsFrontend()) {
            return;
        }

        $environment = $event->getEnvironment();
        $action      = $event->getAction();

        // Only handle if we do not have a manual sorting or we know where to insert.
        // Manual sorting is handled by clipboard.
        if ('createvariant' !== $action->getName()) {
            return;
        }

        // Only run when no response given yet.
        if (null !== $event->getResponse()) {
            return;
        }

        $response = $this->process($environment);
        if (false !== $response) {
            $event->setResponse($response);
        }
    }

    /**
     * Handle the action.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @return string|false
     *
     * @throws DcGeneralRuntimeException         When the DataContainer is not creatable.
     * @throws DcGeneralRuntimeException         When the model to create a variant from was not found.
     */
    public function process(EnvironmentInterface $environment)
    {
        $definition      = $environment->getDataDefinition();
        $basicDefinition = $definition->getBasicDefinition();
        $dataProvider    = $environment->getDataProvider();

        if (!$basicDefinition->isCreatable()) {
            throw new DcGeneralRuntimeException('DataContainer '.$definition->getName().' is not creatable');
        }
        // We only support flat tables, sorry.
        if (BasicDefinitionInterface::MODE_FLAT !== $basicDefinition->getMode()) {
            return false;
        }
        $modelId = ModelId::fromSerialized($environment->getInputProvider()->getParameter('source'));

        /** @var \MetaModels\DcGeneral\Data\Driver $dataProvider */
        $model = $dataProvider->createVariant($dataProvider->getEmptyConfig()->setId($modelId->getId()));
        if (null === $model) {
            throw new DcGeneralRuntimeException(
                sprintf(
                    'Could not find model with id %s for creating a variant.',
                    $modelId
                )
            );
        }

        $metaModel = $this->factory->getMetaModel($model->getProviderName());
        if (null === $metaModel || false === $metaModel->hasVariants()) {
            return false;
        }

        $preFunction = function ($environment, $model) {
            /** @var EnvironmentInterface $environment */
            $createModelEvent = new PreCreateModelEvent($environment, $model);
            $environment->getEventDispatcher()->dispatch($createModelEvent::NAME, $createModelEvent);
        };

        $postFunction = function ($environment, $model) {
            /** @var EnvironmentInterface $environment */
            $createModelEvent = new PostCreateModelEvent($environment, $model);
            $environment->getEventDispatcher()->dispatch($createModelEvent::NAME, $createModelEvent);
        };

        $editMask = new EditMask($environment, $model, null, $preFunction, $postFunction);

        return $editMask->execute();
    }
}