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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright  2015-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View;

use ContaoCommunityAlliance\Contao\Bindings\ContaoEvents;
use ContaoCommunityAlliance\Contao\Bindings\Events\Widget\GetAttributesFromDcaEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\Compatibility\DcCompat;
use ContaoCommunityAlliance\DcGeneral\Contao\RequestScopeDeterminator;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\DecodePropertyValueForWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\GetPropertyOptionsEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\ManipulateWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\BuildWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\PropertyInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;

/**
 * Widget Builder to build Contao frontend widgets.
 */
class DefaultWidgetBuilder
{

    /**
     * The request scope determinator.
     *
     * @var RequestScopeDeterminator
     */
    private $scopeDeterminator;

    /**
     * DefaultWidgetBuilder constructor.
     *
     * @param RequestScopeDeterminator $scopeDeterminator The request scope determinator.
     */
    public function __construct(RequestScopeDeterminator $scopeDeterminator)
    {
        $this->scopeDeterminator = $scopeDeterminator;
    }

    /**
     * Handle the build widget event.
     *
     * @param BuildWidgetEvent $event The event.
     *
     * @return void
     */
    public function handleEvent(BuildWidgetEvent $event)
    {
        // Only run in the frontend or when the widget is not build yet
        if (false === $this->scopeDeterminator->currentScopeIsFrontend() || null !== $event->getWidget()) {
            return;
        }

        $widget = $this->buildWidget($event->getEnvironment(), $event->getProperty(), $event->getModel());

        $event->setWidget($widget);
    }

    /**
     * Build a widget for a given property.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @param PropertyInterface    $property    The property.
     *
     * @param ModelInterface       $model       The current model.
     *
     * @return \Widget
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function buildWidget(
        EnvironmentInterface $environment,
        PropertyInterface $property,
        ModelInterface $model
    ) {
        $dispatcher   = $environment->getEventDispatcher();
        $propertyName = $property->getName();
        $propExtra    = $property->getExtra();
        $defName      = $environment->getDataDefinition()->getName();
        $strClass     = $this->getWidgetClass($property);
        if (null === $strClass) {
            return null;
        }

        $event = new DecodePropertyValueForWidgetEvent($environment, $model);
        $event
            ->setProperty($propertyName)
            ->setValue($model->getProperty($propertyName));

        $dispatcher->dispatch($event::NAME, $event);
        $varValue = $event->getValue();

        $propExtra['required']  = ($varValue == '') && !empty($propExtra['mandatory']);
        $propExtra['tableless'] = true;
        if (isset($propExtra['datepicker'])) {
            $propExtra['class'] = $this->addCssClass($propExtra['class'], 'datepicker');
            $propExtra['class'] = $this->addCssClass($propExtra['class'], '-' . $propExtra['rgxp']);
        }
        if (isset($propExtra['colorpicker'])) {
            $propExtra['class'] = $this->addCssClass($propExtra['class'], 'colorpicker');
            if (isset($propExtra['isHexColor'])) {
                $propExtra['class'] = $this->addCssClass($propExtra['class'], '-hex-color');
            }
        }
        if (isset($propExtra['rte'])) {
            $propExtra['class'] = $this->addCssClass($propExtra['class'], 'rte');
            $propExtra['class'] = $this->addCssClass($propExtra['class'], '-' . $propExtra['rte']);
        }

        // Add the (backend) css class for the frontend as well.
        if (isset($propExtra['tl_class'])) {
            $this->addCssClass($propExtra['class'], $propExtra['tl_class']);
        }

        $arrConfig = array(
            'inputType' => $property->getWidgetType(),
            'label'     => array(
                $property->getLabel(),
                $property->getDescription()
            ),
            'options'   => $this->getOptionsForWidget($environment, $property, $model),
            'eval'      => $propExtra,
        );

        if (isset($propExtra['reference'])) {
            $arrConfig['reference'] = $propExtra['reference'];
        }

        $event = new GetAttributesFromDcaEvent(
            $arrConfig,
            $property->getName(),
            $varValue,
            $propertyName,
            $defName,
            new DcCompat($environment, $model, $propertyName)
        );

        $dispatcher->dispatch(ContaoEvents::WIDGET_GET_ATTRIBUTES_FROM_DCA, $event);
        $preparedConfig = $event->getResult();

        // Remove the "Backend.autoSubmit()", add css class for this purpose
        if (isset($preparedConfig['submitOnChange'])) {
            $preparedConfig['class'] = $this->addCssClass($preparedConfig['class'], 'submitOnChange');
            unset($preparedConfig['onclick'], $preparedConfig['onchange']);
        }

        $widget = new $strClass($preparedConfig, new DcCompat($environment, $model, $propertyName));

        $widget->currentRecord = $model->getId();

        $event = new ManipulateWidgetEvent($environment, $model, $property, $widget);
        $dispatcher->dispatch(ManipulateWidgetEvent::NAME, $event);

        return $widget;
    }

    /**
     * Try to resolve the class name for the widget.
     *
     * @param PropertyInterface $property The property to get the widget class name for.
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    private function getWidgetClass(PropertyInterface $property)
    {
        if (!isset($GLOBALS['TL_FFL'][$property->getWidgetType()])) {
            return null;
        }

        $className = $GLOBALS['TL_FFL'][$property->getWidgetType()];
        if (!class_exists($className)) {
            return null;
        }

        return $className;
    }

    /**
     * Get special labels.
     *
     * @param EnvironmentInterface $environment The environment.
     *
     * @param PropertyInterface    $propInfo    The property for which the options shall be retrieved.
     *
     * @param ModelInterface       $model       The model.
     *
     * @return string[]
     */
    private function getOptionsForWidget(
        EnvironmentInterface $environment,
        PropertyInterface $propInfo,
        ModelInterface $model
    ) {
        $dispatcher = $environment->getEventDispatcher();
        $options    = $propInfo->getOptions();
        $event      = new GetPropertyOptionsEvent($environment, $model);
        $event->setPropertyName($propInfo->getName());
        $event->setOptions($options);
        $dispatcher->dispatch(GetPropertyOptionsEvent::NAME, $event);

        if ($event->getOptions() !== $options) {
            return $event->getOptions();
        }

        return $options;
    }


    /**
     * Add a css class to a string of existing css classes.
     *
     * @param string $classString The string to append the css class to.
     * @param string $class       The css class to add.
     *
     * @return string
     */
    private function addCssClass($classString, $class)
    {
        if (null !== $classString) {
            $classString .= ' ' . $class;
        } else {
            $classString = $class;
        }

        return $classString;
    }
}
