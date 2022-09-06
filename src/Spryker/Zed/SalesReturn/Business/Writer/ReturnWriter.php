<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SalesReturn\Business\Writer;

use ArrayObject;
use Generated\Shared\Transfer\MessageTransfer;
use Generated\Shared\Transfer\OmsEventTriggerResponseTransfer;
use Generated\Shared\Transfer\OrderItemFilterTransfer;
use Generated\Shared\Transfer\ReturnCreateRequestTransfer;
use Generated\Shared\Transfer\ReturnFilterTransfer;
use Generated\Shared\Transfer\ReturnResponseTransfer;
use Generated\Shared\Transfer\ReturnTransfer;
use Spryker\Zed\Kernel\Persistence\EntityManager\TransactionTrait;
use Spryker\Zed\SalesReturn\Business\Generator\ReturnReferenceGeneratorInterface;
use Spryker\Zed\SalesReturn\Business\Reader\ReturnReaderInterface;
use Spryker\Zed\SalesReturn\Business\Triggerer\OmsEventTriggererInterface;
use Spryker\Zed\SalesReturn\Business\Validator\ReturnValidatorInterface;
use Spryker\Zed\SalesReturn\Persistence\SalesReturnEntityManagerInterface;

class ReturnWriter implements ReturnWriterInterface
{
    use TransactionTrait;

    /**
     * @var string
     */
    protected const GLOSSARY_KEY_CREATE_RETURN_ITEM_REQUIRED_FIELDS_ERROR = 'return.create_return.validation.required_item_fields_error';

    /**
     * @uses \Spryker\Zed\Oms\OmsConfig::OMS_EVENT_TRIGGER_RESPONSE
     *
     * @var string
     */
    protected const OMS_EVENT_TRIGGER_RESPONSE = 'oms_event_trigger_response';

    /**
     * @var \Spryker\Zed\SalesReturn\Persistence\SalesReturnEntityManagerInterface
     */
    protected $salesReturnEntityManager;

    /**
     * @var \Spryker\Zed\SalesReturn\Business\Validator\ReturnValidatorInterface
     */
    protected $returnValidator;

    /**
     * @var \Spryker\Zed\SalesReturn\Business\Reader\ReturnReaderInterface
     */
    protected $returnReader;

    /**
     * @var \Spryker\Zed\SalesReturn\Business\Generator\ReturnReferenceGeneratorInterface
     */
    protected $returnReferenceGenerator;

    /**
     * @var \Spryker\Zed\SalesReturn\Business\Triggerer\OmsEventTriggererInterface
     */
    protected $omsEventTriggerer;

    /**
     * @var array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnPreCreatePluginInterface>
     */
    protected $returnPreCreatePlugins;

    /**
     * @param \Spryker\Zed\SalesReturn\Persistence\SalesReturnEntityManagerInterface $salesReturnEntityManager
     * @param \Spryker\Zed\SalesReturn\Business\Validator\ReturnValidatorInterface $returnValidator
     * @param \Spryker\Zed\SalesReturn\Business\Reader\ReturnReaderInterface $returnReader
     * @param \Spryker\Zed\SalesReturn\Business\Generator\ReturnReferenceGeneratorInterface $returnReferenceGenerator
     * @param \Spryker\Zed\SalesReturn\Business\Triggerer\OmsEventTriggererInterface $omsEventTriggerer
     * @param array<\Spryker\Zed\SalesReturnExtension\Dependency\Plugin\ReturnPreCreatePluginInterface> $returnPreCreatePlugins
     */
    public function __construct(
        SalesReturnEntityManagerInterface $salesReturnEntityManager,
        ReturnValidatorInterface $returnValidator,
        ReturnReaderInterface $returnReader,
        ReturnReferenceGeneratorInterface $returnReferenceGenerator,
        OmsEventTriggererInterface $omsEventTriggerer,
        array $returnPreCreatePlugins
    ) {
        $this->salesReturnEntityManager = $salesReturnEntityManager;
        $this->returnValidator = $returnValidator;
        $this->returnReader = $returnReader;
        $this->returnReferenceGenerator = $returnReferenceGenerator;
        $this->omsEventTriggerer = $omsEventTriggerer;
        $this->returnPreCreatePlugins = $returnPreCreatePlugins;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnResponseTransfer
     */
    public function createReturn(ReturnCreateRequestTransfer $returnCreateRequestTransfer): ReturnResponseTransfer
    {
        $this->assertReturnRequirements($returnCreateRequestTransfer);

        if (!$this->checkReturnItemRequirements($returnCreateRequestTransfer)) {
            return $this->createErrorReturnResponse(static::GLOSSARY_KEY_CREATE_RETURN_ITEM_REQUIRED_FIELDS_ERROR);
        }

        $itemTransfers = $this->getOrderItems($returnCreateRequestTransfer);

        $returnCreateRequestTransfer = $this->mapReturnItemsWithOrderItems($returnCreateRequestTransfer, $itemTransfers);
        $returnResponseTransfer = $this->returnValidator->validateReturnRequest($returnCreateRequestTransfer, $itemTransfers);

        if (!$returnResponseTransfer->getIsSuccessful()) {
            return $returnResponseTransfer;
        }

        return $this->getTransactionHandler()->handleTransaction(function () use ($returnCreateRequestTransfer, $itemTransfers) {
            return $this->executeCreateReturnTransaction($returnCreateRequestTransfer, $itemTransfers);
        });
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return \Generated\Shared\Transfer\ReturnResponseTransfer
     */
    protected function executeCreateReturnTransaction(
        ReturnCreateRequestTransfer $returnCreateRequestTransfer,
        ArrayObject $itemTransfers
    ): ReturnResponseTransfer {
        $returnTransfer = $this->createReturnTransfer($returnCreateRequestTransfer);
        $returnTransfer = $this->createReturnItemTransfers($returnTransfer, $itemTransfers);

        $triggerEventReturnData = $this->omsEventTriggerer->triggerOrderItemsReturnEvent($returnTransfer);
        $omsEventTriggerResponseTransfer = $triggerEventReturnData[static::OMS_EVENT_TRIGGER_RESPONSE] ?? null;

        if (
            $omsEventTriggerResponseTransfer instanceof OmsEventTriggerResponseTransfer
            && $omsEventTriggerResponseTransfer->getIsSuccessful() === false
        ) {
            return (new ReturnResponseTransfer())
                ->setIsSuccessful(false)
                ->setMessages($omsEventTriggerResponseTransfer->getMessages());
        }

        return $this->returnReader->getReturn(
            (new ReturnFilterTransfer())->setReturnReference($returnTransfer->getReturnReference()),
        );
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnTransfer
     */
    protected function createReturnTransfer(ReturnCreateRequestTransfer $returnCreateRequestTransfer): ReturnTransfer
    {
        $returnTransfer = (new ReturnTransfer())
            ->setStore($returnCreateRequestTransfer->getStore())
            ->setCustomerReference($this->extractCustomerReference($returnCreateRequestTransfer))
            ->setReturnItems($returnCreateRequestTransfer->getReturnItems());

        $returnReference = $this->returnReferenceGenerator->generateReturnReference($returnTransfer);

        $returnTransfer->setReturnReference($returnReference);
        $returnTransfer = $this->executeReturnPreCreatePlugins($returnTransfer);

        return $this->salesReturnEntityManager->createReturn($returnTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnTransfer $returnTransfer
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return \Generated\Shared\Transfer\ReturnTransfer
     */
    protected function createReturnItemTransfers(ReturnTransfer $returnTransfer, ArrayObject $itemTransfers): ReturnTransfer
    {
        $returnTransfer = $this->mapReturnItemsBeforeCreate($returnTransfer, $itemTransfers);

        foreach ($returnTransfer->getReturnItems() as $returnItemTransfer) {
            $this->salesReturnEntityManager->createReturnItem($returnItemTransfer);
        }

        return $returnTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return void
     */
    protected function assertReturnRequirements(ReturnCreateRequestTransfer $returnCreateRequestTransfer): void
    {
        $returnCreateRequestTransfer
            ->requireReturnItems()
            ->requireStore();
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return bool
     */
    protected function checkReturnItemRequirements(ReturnCreateRequestTransfer $returnCreateRequestTransfer): bool
    {
        foreach ($returnCreateRequestTransfer->getReturnItems() as $returnItemTransfer) {
            $returnItemTransfer->requireOrderItem();
            $itemTransfer = $returnItemTransfer->getOrderItem();

            if (!$itemTransfer->getIdSalesOrderItem() && !$itemTransfer->getUuid()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $message
     *
     * @return \Generated\Shared\Transfer\ReturnResponseTransfer
     */
    protected function createErrorReturnResponse(string $message): ReturnResponseTransfer
    {
        $messageTransfer = (new MessageTransfer())
            ->setValue($message);

        return (new ReturnResponseTransfer())
            ->setIsSuccessful(false)
            ->addMessage($messageTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnTransfer $returnTransfer
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return \Generated\Shared\Transfer\ReturnTransfer
     */
    public function mapReturnItemsBeforeCreate(ReturnTransfer $returnTransfer, ArrayObject $itemTransfers): ReturnTransfer
    {
        $returnTransfer->requireIdSalesReturn();

        foreach ($returnTransfer->getReturnItems() as $returnItemTransfer) {
            $returnItemTransfer->setIdSalesReturn($returnTransfer->getIdSalesReturn());
        }

        return $returnTransfer;
    }

    /**
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return array<\Generated\Shared\Transfer\ItemTransfer>
     */
    protected function indexOrderItemsByUuid(ArrayObject $itemTransfers): array
    {
        $indexedOrderItems = [];

        foreach ($itemTransfers as $itemTransfer) {
            $indexedOrderItems[$itemTransfer->getUuid()] = $itemTransfer;
        }

        return $indexedOrderItems;
    }

    /**
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return array<\Generated\Shared\Transfer\ItemTransfer>
     */
    protected function indexOrderItemsById(ArrayObject $itemTransfers): array
    {
        $indexedOrderItems = [];

        foreach ($itemTransfers as $itemTransfer) {
            $indexedOrderItems[$itemTransfer->getIdSalesOrderItem()] = $itemTransfer;
        }

        return $indexedOrderItems;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer>
     */
    protected function getOrderItems(ReturnCreateRequestTransfer $returnCreateRequestTransfer): ArrayObject
    {
        $orderItemFilterTransfer = $this->mapReturnCreateRequestTransferToOrderItemFilterTransfer(
            $returnCreateRequestTransfer,
            new OrderItemFilterTransfer(),
        );

        return $this->returnReader->getOrderItems($orderItemFilterTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     * @param \Generated\Shared\Transfer\OrderItemFilterTransfer $orderItemFilterTransfer
     *
     * @return \Generated\Shared\Transfer\OrderItemFilterTransfer
     */
    protected function mapReturnCreateRequestTransferToOrderItemFilterTransfer(
        ReturnCreateRequestTransfer $returnCreateRequestTransfer,
        OrderItemFilterTransfer $orderItemFilterTransfer
    ): OrderItemFilterTransfer {
        $customerReference = $this->extractCustomerReference($returnCreateRequestTransfer);

        if ($customerReference) {
            $orderItemFilterTransfer->addCustomerReference($customerReference);
        }

        foreach ($returnCreateRequestTransfer->getReturnItems() as $returnItemTransfer) {
            $itemTransfer = $returnItemTransfer->getOrderItem();

            if ($itemTransfer->getUuid()) {
                $orderItemFilterTransfer->addSalesOrderItemUuid($itemTransfer->getUuidOrFail());

                continue;
            }

            $orderItemFilterTransfer->addSalesOrderItemId($itemTransfer->getIdSalesOrderItem());
        }

        return $orderItemFilterTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     *
     * @return string|null
     */
    protected function extractCustomerReference(ReturnCreateRequestTransfer $returnCreateRequestTransfer): ?string
    {
        if (!$returnCreateRequestTransfer->getCustomer()) {
            return null;
        }

        return $returnCreateRequestTransfer->getCustomer()->getCustomerReference();
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnTransfer $returnTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnTransfer
     */
    protected function executeReturnPreCreatePlugins(ReturnTransfer $returnTransfer): ReturnTransfer
    {
        foreach ($this->returnPreCreatePlugins as $returnPreCreatePlugin) {
            $returnTransfer = $returnPreCreatePlugin->preCreate($returnTransfer);
        }

        return $returnTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ReturnCreateRequestTransfer $returnCreateRequestTransfer
     * @param \ArrayObject<int, \Generated\Shared\Transfer\ItemTransfer> $itemTransfers
     *
     * @return \Generated\Shared\Transfer\ReturnCreateRequestTransfer
     */
    protected function mapReturnItemsWithOrderItems(
        ReturnCreateRequestTransfer $returnCreateRequestTransfer,
        ArrayObject $itemTransfers
    ): ReturnCreateRequestTransfer {
        $indexedItemsById = $this->indexOrderItemsById($itemTransfers);
        $indexedItemsByUuid = $this->indexOrderItemsByUuid($itemTransfers);

        foreach ($returnCreateRequestTransfer->getReturnItems() as $returnItemTransfer) {
            $orderItemTransfer = $returnItemTransfer->getOrderItemOrFail();
            $idSalesOrderItem = $orderItemTransfer->getIdSalesOrderItem();
            $orderItemUuid = $orderItemTransfer->getUuid();

            if (isset($indexedItemsById[$idSalesOrderItem]) || isset($indexedItemsByUuid[$orderItemUuid])) {
                $returnItemTransfer->setOrderItem(
                    $indexedItemsById[$idSalesOrderItem] ?? $indexedItemsByUuid[$orderItemUuid],
                );
            }
        }

        return $returnCreateRequestTransfer;
    }
}
