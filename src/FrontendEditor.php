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

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend;

use ContaoCommunityAlliance\DcGeneral\Action;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Factory\DcGeneralFactory;
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
    private $dispatcher;

    /**
     * The translator.
     *
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * The already populated environments.
     *
     * @var EnvironmentInterface[]
     */
    private static $environments;

    /**
     * Create a new instance.
     *
     * @param EventDispatcherInterface $dispatcher The event dispatcher.
     *
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
     *
     * @param string $defaultAction The default action to issue, if none provided by the input provider.
     *
     * @return string
     */
    public function editFor($containerName, $defaultAction = 'showAll')
    {
        $environment = $this->createDcGeneral($containerName);
        $actionName  = $environment->getInputProvider()->getParameter('act') ?: $defaultAction;
        $action      = new Action($actionName);
        $event       = new ActionEvent($environment, $action);

        $this->dispatcher->dispatch(DcGeneralEvents::ACTION, $event);

        if (!$result = $event->getResponse()) {
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
    public function createDcGeneral($containerName)
    {
        if (null === self::$environments[$containerName]) {
            $factory   = new DcGeneralFactory();
            $dcGeneral = $factory
                ->setContainerName($containerName)
                ->setEventDispatcher($this->dispatcher)
                ->setTranslator($this->translator)
                ->createDcGeneral();

            self::$environments[$containerName] = $dcGeneral->getEnvironment();
        }

        return self::$environments[$containerName];
    }
}
