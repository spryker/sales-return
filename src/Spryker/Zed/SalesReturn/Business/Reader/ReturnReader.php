<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SalesReturn\Business\Reader;

use ArrayObject;
use Generated\Shared\Transfer\MessageTransfer;
use Generated\Shared\Transfer\OrderItemFilterTransfer;
use Generated\Shared\Transfer\ReturnCollectionTransfer;
use Generated\Shared\Transfer\ReturnFilterTransfer;
use Generated\Shared\Transfer\ReturnItemFilterTransfer;
use Generated\Shared\Transfer\ReturnResponseTransfer;
use Generated\Shared\Transfer\ReturnTransfer;
use Spryker\Zed\SalesReturn\Business\Calculator\ReturnTotalCalculatorInterface;
use Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToSalesFacadeInterface;
use Spryker\Zed\SalesReturn\Persistence\SalesReturnRepositoryInterface;

class ReturnReader implements ReturnReaderInterface
{
    protected const GLOSSARY_KEY_RETURN_NOT_EXISTS = 'return.validation.error.not_exists';

    /**
     * @var \Spryker\Zed\SalesReturn\Persistence\SalesReturnRepositoryInterface
     */
    protected $salesReturnRepository;

    /**
     * @var \Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToSalesFacadeInterface
     */
    protected $salesFacade;

    /**
     * @var \Spryker\Zed\SalesReturn\Business\Calculator\ReturnTotalCalculatorInterface
     */
    protected $returnTotalCalculator;

    /**
     * @param \Spryker\Zed\SalesReturn\Persistence\SalesReturnRepositoryInterface $salesReturnRepository
     * @param \Spryker\Zed\SalesReturn\Dependency\Facade\SalesReturnToSalesFacadeInterface $salesFacade
     * @param \Spryker\Zed\SalesReturn\Business\Calculator\ReturnTotalCalculatorInterface $returnTotalCalculator
     */
    public function __construct(
        SalesReturnRepositoryInterface $salesReturnRepository,
        SalesReturnToSalesFacadeInterface $salesFacade,
        ReturnTotalCalculatorInterface $returnTotalCalculator
    ) {
        $this->salesReturnRepository = $salesReturnRepository;
        $this->salesFacade = $salesFacade;
        $this->returnTotalCalculator = $returnTotalCalculator;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnFilterTransfer $returnFilterTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnResponseTransfer
     */
    public function getReturn(ReturnFilterTransfer $returnFilterTransfer): ReturnResponseTransfer
    {
        $returnFilterTransfer->requireReturnReference();

        $returnTransfer = $this
            ->getReturnCollection($returnFilterTransfer)
            ->getReturns()
            ->getIterator()
            ->current();

        if (!$returnTransfer) {
            return $this->createErrorResponse(static::GLOSSARY_KEY_RETURN_NOT_EXISTS);
        }

        return (new ReturnResponseTransfer())
            ->setIsSuccessful(true)
            ->setReturn($returnTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnFilterTransfer $returnFilterTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnCollectionTransfer
     */
    public function getReturnCollection(ReturnFilterTransfer $returnFilterTransfer): ReturnCollectionTransfer
    {
        $returnCollectionTransfer = $this->salesReturnRepository->getReturnCollectionByFilter($returnFilterTransfer);

        $returnCollectionTransfer = $this->expandReturnCollectionWithReturnItems($returnCollectionTransfer);
        $returnCollectionTransfer = $this->expandReturnCollectionWithReturnTotals($returnCollectionTransfer);

        return $returnCollectionTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\OrderItemFilterTransfer $orderItemFilterTransfer
     *
     * @return \ArrayObject|\Generated\Shared\Transfer\ItemTransfer[]
     */
    public function getOrderItems(OrderItemFilterTransfer $orderItemFilterTransfer): ArrayObject
    {
        return $this->salesFacade
            ->getOrderItems($orderItemFilterTransfer)
            ->getItems();
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCollectionTransfer $returnCollectionTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnCollectionTransfer
     */
    protected function expandReturnCollectionWithReturnItems(ReturnCollectionTransfer $returnCollectionTransfer): ReturnCollectionTransfer
    {
        $returnIds = $this->extractReturnIds($returnCollectionTransfer);
        $returnItemFilterTransfer = (new ReturnItemFilterTransfer())->setReturnIds($returnIds);

        $returnItemTransfers = $this->salesReturnRepository->getReturnItemsByFilter($returnItemFilterTransfer);
        $mappedReturnItemTransfers = $this->mapReturnItemsByIdReturn($returnItemTransfers);

        foreach ($returnCollectionTransfer->getReturns() as $returnTransfer) {
            $returnTransfer->setReturnItems(
                new ArrayObject($mappedReturnItemTransfers[$returnTransfer->getIdSalesReturn()] ?? [])
            );

            $this->expandReturnWithOrderItems($returnTransfer);
        }

        return $returnCollectionTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCollectionTransfer $returnCollectionTransfer
     *
     * @return int[]
     */
    protected function extractReturnIds(ReturnCollectionTransfer $returnCollectionTransfer): array
    {
        $returnIds = [];

        foreach ($returnCollectionTransfer->getReturns() as $returnTransfer) {
            $returnIds[] = $returnTransfer->getIdSalesReturn();
        }

        return $returnIds;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnTransfer $returnTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnTransfer
     */
    protected function expandReturnWithOrderItems(ReturnTransfer $returnTransfer): ReturnTransfer
    {
        $salesOrderItemIds = $this->extractSalesOrderItemIds($returnTransfer);
        $orderItemFilterTransfer = (new OrderItemFilterTransfer())->setSalesOrderItemIds($salesOrderItemIds);

        $itemTransfers = $this->getOrderItems($orderItemFilterTransfer);
        $mappedItemTransfers = $this->mapOrderItemsByIdSalesOrderItem($itemTransfers);

        foreach ($returnTransfer->getReturnItems() as $returnItemTransfer) {
            $returnItemTransfer->setOrderItem(
                $mappedItemTransfers[$returnItemTransfer->getOrderItem()->getIdSalesOrderItem()] ?? null
            );
        }

        return $returnTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnTransfer $returnTransfer
     *
     * @return int[]
     */
    protected function extractSalesOrderItemIds(ReturnTransfer $returnTransfer): array
    {
        $salesOrderItemIds = [];

        foreach ($returnTransfer->getReturnItems() as $returnItemTransfer) {
            $salesOrderItemIds[] = $returnItemTransfer->getOrderItem()->getIdSalesOrderItem();
        }

        return $salesOrderItemIds;
    }

    /**
     * @param \ArrayObject|\Generated\Shared\Transfer\ItemTransfer[] $itemTransfers
     *
     * @return \Generated\Shared\Transfer\ItemTransfer[]
     */
    protected function mapOrderItemsByIdSalesOrderItem(ArrayObject $itemTransfers): array
    {
        $mappedItemTransfers = [];

        foreach ($itemTransfers as $itemTransfer) {
            $mappedItemTransfers[$itemTransfer->getIdSalesOrderItem()] = $itemTransfer;
        }

        return $mappedItemTransfers;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnItemTransfer[] $returnItemTransfers
     *
     * @return \Generated\Shared\Transfer\ReturnItemTransfer[][]
     */
    protected function mapReturnItemsByIdReturn(array $returnItemTransfers): array
    {
        $mappedReturnItemTransfers = [];

        foreach ($returnItemTransfers as $returnItemTransfer) {
            $mappedReturnItemTransfers[$returnItemTransfer->getIdSalesReturn()][] = $returnItemTransfer;
        }

        return $mappedReturnItemTransfers;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCollectionTransfer $returnCollectionTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnCollectionTransfer
     */
    protected function expandReturnCollectionWithReturnTotals(ReturnCollectionTransfer $returnCollectionTransfer): ReturnCollectionTransfer
    {
        foreach ($returnCollectionTransfer->getReturns() as $returnTransfer) {
            $returnTransfer->setReturnTotals(
                $this->returnTotalCalculator->calculateReturnTotals($returnTransfer)
            );
        }

        return $returnCollectionTransfer;
    }

    /**
     * @param string $message
     *
     * @return \Generated\Shared\Transfer\ReturnResponseTransfer
     */
    protected function createErrorResponse(string $message): ReturnResponseTransfer
    {
        $messageTransfer = (new MessageTransfer())
            ->setValue($message);

        return (new ReturnResponseTransfer())
            ->setIsSuccessful(false)
            ->addMessage($messageTransfer);
    }
}
