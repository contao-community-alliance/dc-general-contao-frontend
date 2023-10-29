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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @author     Ingolf Steinhardt <info@e-spin.de>
 * @copyright  2015-2023 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general-contao-frontend/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\ContaoFrontend\View;

use Contao\FormTextArea;
use Contao\Input;
use Contao\Widget;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\BuildWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\EncodePropertyValueFromWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\ContaoFrontend\Event\DcGeneralFrontendEvents;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\PropertyValueBag;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\ContainerInterface;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Class WidgetManager.
 *
 * This class is responsible for creating widgets and processing data through them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WidgetManager
{
    /**
     * The environment in use.
     *
     * @var EnvironmentInterface
     */
    protected EnvironmentInterface $environment;

    /**
     * The model for which widgets shall be generated.
     *
     * @var ModelInterface
     */
    protected ModelInterface $model;

    /**
     * Create a new instance.
     *
     * @param EnvironmentInterface $environment The environment in use.
     * @param ModelInterface       $model       The model for which widgets shall be generated.
     */
    public function __construct(EnvironmentInterface $environment, ModelInterface $model)
    {
        $this->environment = $environment;
        $this->model       = $model;
    }

    /**
     * Retrieve the instance of a widget for the given property.
     *
     * @param string                $property Name of the property for which the widget shall be retrieved.
     * @param PropertyValueBag|null $valueBag The input values to use (optional).
     *
     * @return \Widget|null
     *
     * @throws DcGeneralRuntimeException         When No widget could be built.
     * @throws DcGeneralInvalidArgumentException When property is not defined in the property definitions.
     */
    public function getWidget($property, PropertyValueBag $valueBag = null)
    {
        $environment = $this->getEnvironment();
        $dispatcher  = $environment->getEventDispatcher();
        assert($dispatcher instanceof EventDispatcherInterface);
        $dataDefinition = $environment->getDataDefinition();
        assert($dataDefinition instanceof ContainerInterface);

        $propertyDefinitions = $dataDefinition->getPropertiesDefinition();

        if (!$propertyDefinitions->hasProperty($property)) {
            throw new DcGeneralInvalidArgumentException(
                'Property ' . $property . ' is not defined in propertyDefinitions.'
            );
        }

        $model = clone $this->model;
        $model->setId($this->model->getId());

        if ($valueBag) {
            $values = new PropertyValueBag($valueBag->getArrayCopy());

            $controller = $this->environment->getController();
            assert($controller instanceof ContainerInterface);

            $controller->updateModelFromPropertyBag($model, $values);
        }

        $propertyDefinition = $propertyDefinitions->getProperty($property);
        $event              = new BuildWidgetEvent($environment, $model, $propertyDefinition);

        $dispatcher->dispatch($event, DcGeneralFrontendEvents::BUILD_WIDGET);
        if (!$event->getWidget()) {
            throw new DcGeneralRuntimeException(
                \sprintf('Widget was not build for property %s::%s.', $this->model->getProviderName(), $property)
            );
        }

        return $event->getWidget();
    }

    /**
     * Render the widget for the named property.
     *
     * @param string           $property     The name of the property for which the widget shall be rendered.
     * @param bool             $ignoreErrors Flag if the error property of the widget shall get cleared prior rendering.
     * @param PropertyValueBag $valueBag     The input values to use (optional).
     *
     * @return string
     *
     * @throws DcGeneralRuntimeException or unknown properties.
     */
    public function renderWidget($property, $ignoreErrors = false, PropertyValueBag $valueBag = null)
    {
        $widget = $this->getWidget($property, $valueBag);

        if (!$widget) {
            throw new DcGeneralRuntimeException('No widget for property ' . $property);
        }

        if ($ignoreErrors) {
            // Clean the errors array and fix up the CSS class.
            $reflection = new \ReflectionProperty(get_class($widget), 'arrErrors');
            $reflection->setAccessible(true);
            $reflection->setValue($widget, []);
            $reflection = new \ReflectionProperty(get_class($widget), 'strClass');
            $reflection->setAccessible(true);
            $reflection->setValue($widget, str_replace('error', '', $reflection->getValue($widget)));
        } else {
            if (
                $valueBag && $valueBag->hasPropertyValue($property)
                && $valueBag->isPropertyValueInvalid($property)
            ) {
                foreach ($valueBag->getPropertyValueErrors($property) as $error) {
                    $widget->addError($error);
                }
            }
        }

        return $widget->parse();
    }

    /**
     * Process the input values from the input provider and update the information in the value bag.
     *
     * @param PropertyValueBag $valueBag The value bag to update.
     *
     * @return void
     */
    public function processInput(PropertyValueBag $valueBag): void
    {
        $post = $this->hijackPost($valueBag);

        // Now get and validate the widgets.
        foreach (\array_keys($valueBag->getArrayCopy()) as $property) {
            $this->processProperty($valueBag, $property);
        }

        $this->restorePost($post);
    }

    /**
     * Process a single property.
     *
     * @param PropertyValueBag $valueBag The value bag to update.
     * @param string           $property The property to process.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException         When No widget could be build.
     * @throws DcGeneralInvalidArgumentException When property is not defined in the property definitions.
     */
    private function processProperty(PropertyValueBag $valueBag, string $property): void
    {
        // NOTE: the passed input values are RAW DATA from the input provider - aka widget known values and not
        // native data as in the model.
        // Therefore, we do not need to decode them but MUST encode them.
        $widget = $this->getWidget($property, $valueBag);
        assert($widget instanceof Widget);
        $widget->validate();

        if ($widget->hasErrors()) {
            foreach ($widget->getErrors() as $error) {
                $valueBag->markPropertyValueAsInvalid($property, $error);
            }

            return;
        }

        if (!$widget->submitInput()) {
            // Handle as abstaining property.
            $valueBag->removePropertyValue($property);
            return;
        }

        try {
            // See https://github.com/contao/contao/blob/7e6bacd4e/core-bundle/src/Resources/contao/forms/FormTextArea.php#L147
            if ($widget instanceof FormTextArea) {
                /** @psalm-suppress UndefinedMagicPropertyFetch */
                $valueBag->setPropertyValue($property, $this->encodeValue($property, $widget->rawValue, $valueBag));
                return;
            }
            $valueBag->setPropertyValue($property, $this->encodeValue($property, $widget->value, $valueBag));
        } catch (\Exception $e) {
            $widget->addError($e->getMessage());
            foreach ($widget->getErrors() as $error) {
                $valueBag->markPropertyValueAsInvalid($property, $error);
            }
        }
    }

    /**
     * Remember current POST data and overwrite it with the values from the value bag.
     *
     * The previous values are returned and can be restored via a call to restorePost().
     *
     * @param PropertyValueBag $valueBag The value bag to retrieve the new post data from.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    private function hijackPost(PropertyValueBag $valueBag): array
    {
        $post  = $_POST;
        $_POST = [];
        Input::resetCache();

        // Set all POST data, these get used within the Widget::validate() method.
        foreach ($valueBag as $property => $value) {
            $_POST[$property] = $value;
        }

        return $post;
    }

    /**
     * Restore the post values with the passed values.
     *
     * @param array $post The Post values to restore.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    private function restorePost(array $post): void
    {
        $_POST = $post;
        Input::resetCache();
    }

    /**
     * Retrieve the environment.
     *
     * @return EnvironmentInterface
     */
    private function getEnvironment(): EnvironmentInterface
    {
        return $this->environment;
    }

    /**
     * Encode a value from the widget to native data of the data provider via event.
     *
     * @param string           $property The property.
     * @param mixed            $value    The value of the property.
     * @param PropertyValueBag $valueBag The property value bag the property value originates from.
     *
     * @return mixed
     */
    private function encodeValue(string $property, mixed $value, PropertyValueBag $valueBag): mixed
    {
        $environment = $this->getEnvironment();

        $event = new EncodePropertyValueFromWidgetEvent($environment, $this->model, $valueBag);
        $event
            ->setProperty($property)
            ->setValue($value);

        $dispatcher = $environment->getEventDispatcher();
        assert($dispatcher instanceof EventDispatcherInterface);

        $dispatcher->dispatch($event, EncodePropertyValueFromWidgetEvent::NAME);

        return $event->getValue();
    }
}
