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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2024 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend;

use ContaoCommunityAlliance\DcGeneral\Action;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Factory\DcGeneralFactory;
use ContaoCommunityAlliance\DcGeneral\InputProviderInterface;
use ContaoCommunityAlliance\Translator\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * This class performs the real frontend editing.
 */
class FrontendEditor
{
    /**
     * The event dispatcher.
     *
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $dispatcher;

    /**
     * The translator.
     *
     * @var TranslatorInterface
     */
    private TranslatorInterface $translator;

    /**
     * The already populated environments.
     *
     * @var EnvironmentInterface[]
     */
    private static array $environments = [];

    /**
     * Create a new instance.
     *
     * @param EventDispatcherInterface $dispatcher The event dispatcher.
     * @param TranslatorInterface      $translator The translator.
     */
    public function __construct(EventDispatcherInterface $dispatcher, TranslatorInterface $translator)
    {
        $this->dispatcher = $dispatcher;
        $this->translator = $translator;
    }

    /**
     * Create a frontend editor for the given table.
     *
     * @param string $containerName The name of the data container to edit.
     * @param string $defaultAction The default action to issue, if none provided by the input provider.
     *
     * @return string
     */
    public function editFor($containerName, $defaultAction = 'showAll'): string
    {
        $environment = $this->createDcGeneral($containerName);

        $inputProvider = $environment->getInputProvider();
        assert($inputProvider instanceof InputProviderInterface);

        $actionName = $inputProvider->getParameter('act') ?: $defaultAction;
        assert(\is_string($actionName));

        $action = new Action($actionName);
        $event  = new ActionEvent($environment, $action);

        // If the action parameter is not set, it is set. So that the action parameter can be used everywhere.
        if (false === ($hasActionName = $inputProvider->hasParameter('act'))) {
            $inputProvider->setParameter('act', $actionName);
        }

        $this->dispatcher->dispatch($event, DcGeneralEvents::ACTION);

        if (false === $hasActionName) {
            $inputProvider->unsetParameter('act');
        }

        if (null === ($result = $event->getResponse())) {
            return 'Action ' . $action->getName() . ' is not supported yet.';
        }

        return $result;
    }

    /**
     * Create the dc-general and return it's environment instance.
     *
     * @param string $containerName The name of the data container to edit.
     *
     * @return EnvironmentInterface
     */
    public function createDcGeneral($containerName): EnvironmentInterface
    {
        if (!array_key_exists($containerName, self::$environments)) {
            $dcGeneral = (new DcGeneralFactory())
                ->setContainerName($containerName)
                ->setEventDispatcher($this->dispatcher)
                ->setTranslator($this->translator)
                ->createDcGeneral();

            self::$environments[$containerName] = $dcGeneral->getEnvironment();
        }

        return self::$environments[$containerName];
    }
}
