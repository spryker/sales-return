<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SalesReturn;

use Orm\Zed\Sales\Persistence\SpySalesOrderItemQuery;
use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToOmsFacadeBridge;
use Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToSalesFacadeBridge;
use Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToStoreFacadeBridge;
use Spryker\Zed\SalesReturn\Dependency\Service\SalesReturnToUtilDateTimeServiceBridge;

/**
 * @method \Spryker\Zed\SalesReturn\SalesReturnConfig getConfig()
 */
class SalesReturnDependencyProvider extends AbstractBundleDependencyProvider
{
    /**
     * @var string
     */
    public const FACADE_STORE = 'FACADE_STORE';

    /**
     * @var string
     */
    public const FACADE_SALES = 'FACADE_SALES';

    /**
     * @var string
     */
    public const FACADE_OMS = 'FACADE_OMS';

    /**
     * @var string
     */
    public const SERVICE_UTIL_DATE_TIME = 'SERVICE_UTIL_DATE_TIME';

    /**
     * @var string
     */
    public const PROPEL_QUERY_SALES_ORDER_ITEM = 'PROPEL_QUERY_SALES_ORDER_ITEM';

    /**
     * @var string
     */
    public const PLUGINS_RETURN_PRE_CREATE = 'PLUGINS_RETURN_PRE_CREATE';

    /**
     * @var string
     */
    public const PLUGINS_RETURN_CREATE_REQUEST_VALIDATOR = 'PLUGINS_RETURN_CREATE_REQUEST_VALIDATOR';

    /**
     * @var string
     */
    public const PLUGINS_RETURN_EXPANDER = 'PLUGINS_RETURN_EXPANDER';

    /**
     * @deprecated Will be removed without replacement.
     *
     * @var string
     */
    public const PLUGINS_RETURN_COLLECTION_EXPANDER = 'PLUGINS_RETURN_COLLECTION_EXPANDER';

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);

        $container = $this->addStoreFacade($container);
        $container = $this->addSalesFacade($container);
        $container = $this->addOmsFacade($container);
        $container = $this->addUtilDateTimeService($container);
        $container = $this->addReturnPreCreatePlugins($container);
        $container = $this->addReturnRequestValidatorPlugins($container);
        $container = $this->addReturnCollectionExpanderPlugins($container);
        $container = $this->addReturnExpanderPlugins($container);

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    public function providePersistenceLayerDependencies(Container $container): Container
    {
        $container = parent::providePersistenceLayerDependencies($container);

        $container = $this->addSalesOrderItemPropelQuery($container);

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addStoreFacade(Container $container): Container
    {
        $container->set(static::FACADE_STORE, function (Container $container) {
            return new SalesReturnToStoreFacadeBridge($container->getLocator()->store()->facade());
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addSalesFacade(Container $container): Container
    {
        $container->set(static::FACADE_SALES, function (Container $container) {
            return new SalesReturnToSalesFacadeBridge($container->getLocator()->sales()->facade());
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addOmsFacade(Container $container): Container
    {
        $container->set(static::FACADE_OMS, function (Container $container) {
            return new SalesReturnToOmsFacadeBridge($container->getLocator()->oms()->facade());
        });

        return $container;
    }

    /**
     * @module Sales
     *
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addSalesOrderItemPropelQuery(Container $container): Container
    {
        $container->set(static::PROPEL_QUERY_SALES_ORDER_ITEM, $container->factory(function () {
            return SpySalesOrderItemQuery::create();
        }));

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addUtilDateTimeService(Container $container): Container
    {
        $container->set(static::SERVICE_UTIL_DATE_TIME, function (Container $container) {
            return new SalesReturnToUtilDateTimeServiceBridge(
                $container->getLocator()->utilDateTime()->service(),
            );
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addReturnPreCreatePlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_RETURN_PRE_CREATE, function () {
            return $this->getReturnPreCreatePlugins();
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addReturnRequestValidatorPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_RETURN_CREATE_REQUEST_VALIDATOR, function () {
            return $this->getReturnCreateRequestValidatorPlugins();
        });

        return $container;
    }

    /**
     * @deprecated Will be removed without replacement.
     *
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addReturnCollectionExpanderPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_RETURN_COLLECTION_EXPANDER, function () {
            return $this->getReturnCollectionExpanderPlugins();
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addReturnExpanderPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_RETURN_EXPANDER, function () {
            return $this->getReturnExpanderPlugins();
        });

        return $container;
    }

    /**
     * @return array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnPreCreatePluginInterface>
     */
    protected function getReturnPreCreatePlugins(): array
    {
        return [];
    }

    /**
     * @return array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnCreateRequestValidatorPluginInterface>
     */
    protected function getReturnCreateRequestValidatorPlugins(): array
    {
        return [];
    }

    /**
     * @deprecated Will be removed without replacement.
     *
     * @return array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnCollectionExpanderPluginInterface>
     */
    protected function getReturnCollectionExpanderPlugins(): array
    {
        return [];
    }

    /**
     * @return array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnExpanderPluginInterface>
     */
    protected function getReturnExpanderPlugins(): array
    {
        return [];
    }
}
