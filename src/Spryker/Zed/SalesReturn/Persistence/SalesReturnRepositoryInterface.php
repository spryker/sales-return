<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\SalesReturn\Persistence;

use Generated\Shared\Transfer\ItemTransfer;
use Generated\Shared\Transfer\ReturnCollectionTransfer;
use Generated\Shared\Transfer\ReturnFilterTransfer;
use Generated\Shared\Transfer\ReturnItemFilterTransfer;
use Generated\Shared\Transfer\ReturnReasonFilterTransfer;

interface SalesReturnRepositoryInterface
{
    /**
     * @param int $idSalesOrderItem
     *
     * @return \Generated\Shared\Transfer\ItemTransfer|null
     */
    public function findSalesOrderItemByIdSalesOrder(int $idSalesOrderItem): ?ItemTransfer;

    /**
     * @param \Generated\Shared\Transfer\ReturnReasonFilterTransfer $returnReasonFilterTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnReasonTransfer[]
     */
    public function getReturnReasons(ReturnReasonFilterTransfer $returnReasonFilterTransfer): array;

    /**
     * @param string $customerReference
     *
     * @return int
     */
    public function countCustomerReturns(string $customerReference): int;

    /**
     * @param \Generated\Shared\Transfer\ReturnFilterTransfer $returnFilterTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnCollectionTransfer
     */
    public function getReturnCollectionByFilter(
        ReturnFilterTransfer $returnFilterTransfer
    ): ReturnCollectionTransfer;

    /**
     * @param \Generated\Shared\Transfer\ReturnItemFilterTransfer $returnItemFilterTransfer
     *
     * @return \Generated\Shared\Transfer\ReturnItemTransfer[]
     */
    public function getReturnItemsByFilter(ReturnItemFilterTransfer $returnItemFilterTransfer): array;
}
