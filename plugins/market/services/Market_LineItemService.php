<?php

namespace Craft;
use Market\Helpers\MarketDbHelper;

/**
 * Class Market_LineItemService
 * @package Craft
 */
class Market_LineItemService extends BaseApplicationComponent
{
	/**
	 * @param int $id
	 * @return Market_LineItemModel
	 */
	public function getById($id)
	{
		$lineItem = Market_LineItemRecord::model()->findById($id);
		return Market_LineItemModel::populateModel($lineItem);
	}

	/**
	 * @param int $id
	 * @return Market_LineItemModel[]
	 */
	public function getAllByOrderId($id)
	{
		$lineItems = Market_LineItemRecord::model()->findAllByAttributes(['orderId' => $id]);
		return Market_LineItemModel::populateModels($lineItems);
	}

	/**
	 * Find line item by order and variant
	 *
	 * @param int $orderId
	 * @param int $variantId
	 * @return Market_LineItemModel
	 */
	public function getByOrderVariant($orderId, $variantId)
	{
		$variant = Market_LineItemRecord::model()->findByAttributes([
			'orderId' => $orderId,
			'variantId' => $variantId,
		]);
		return Market_LineItemModel::populateModel($variant);
	}

	/**
	 * @TODO check that the line item belongs to the current user
	 * @param int $lineItemId
	 * @param int $qty
	 * @param string $error
	 * @return bool
	 * @throws Exception
	 */
	public function updateQty($lineItemId, $qty, &$error = '')
	{
		$lineItem = craft()->market_lineItem->getById($lineItemId);

		if(!$lineItem->id) {
			throw new Exception('Line item not found');
		}

		$lineItem->qty = $qty;

		if($this->save($lineItem)) {
            craft()->market_order->save($lineItem->order);

			return true;
		} else {
			$errors = $lineItem->getAllErrors();
			$error = array_pop($errors);
			return false;
		}
	}

	/**
	 * @param Market_LineItemModel $lineItem
	 * @return bool
	 * @throws \Exception
	 */
	public function save(Market_LineItemModel $lineItem)
	{
		if (!$lineItem->id) {
			$lineItemRecord = new Market_LineItemRecord();
		} else {
			$lineItemRecord = Market_LineItemRecord::model()->findById($lineItem->id);

			if (!$lineItemRecord) {
				throw new Exception(Craft::t('No line item exists with the ID “{id}”', array('id' => $lineItem->id)));
			}
		}

        $lineItem->subtotal = $lineItem->salePrice * $lineItem->qty;
        $lineItem->total = $lineItem->subtotal + $lineItem->shipTotal;
        $lineItem->totalIncTax = $lineItem->total + $lineItem->taxAmount;

        $lineItemRecord->variantId 		= $lineItem->variantId;
		$lineItemRecord->orderId 		= $lineItem->orderId;
		$lineItemRecord->taxCategoryId  = $lineItem->taxCategoryId;
		$lineItemRecord->qty 			= $lineItem->qty;
		$lineItemRecord->price 			= $lineItem->price;
		$lineItemRecord->salePrice 		= $lineItem->salePrice;
		$lineItemRecord->optionsJson 	= $lineItem->optionsJson;
		$lineItemRecord->taxAmount      = $lineItem->taxAmount;
		$lineItemRecord->subtotal       = $lineItem->subtotal;
		$lineItemRecord->total          = $lineItem->total;
		$lineItemRecord->totalIncTax    = $lineItem->totalIncTax;

        $lineItemRecord->validate();
		$lineItem->addErrors($lineItemRecord->getErrors());

		MarketDbHelper::beginStackedTransaction();
		try {
			if (!$lineItem->hasErrors()) {
				$lineItemRecord->save(false);
				$lineItemRecord->id = $lineItem->id;

				MarketDbHelper::commitStackedTransaction();
				return true;
			}
		} catch(\Exception $e) {
			MarketDbHelper::rollbackStackedTransaction();
			throw $e;
		}

		return false;
	}

	/**
	 * @param int $variantId
	 * @param int $orderId
	 * @param int $qty
	 * @return Market_LineItemModel
	 */
	public function create($variantId, $orderId, $qty)
	{
		$lineItem = new Market_LineItemModel();
		$lineItem->variantId = $variantId;
		$lineItem->qty = $qty;
		$lineItem->orderId = $orderId;

		$variant = craft()->market_variant->getById($variantId);
		if($variant->id) {
			$lineItem->price = $lineItem->salePrice = $variant->price;

			$options = $variant->attributes;
			$options['optionValues'] = $variant->getOptionValuesArray();
			$lineItem->optionsJson = $options;
            $lineItem->taxCategoryId = $variant->product->taxCategoryId;

            $sales = craft()->market_sale->getForVariant($variant);
            foreach($sales as $sale) {
                $lineItem->salePrice += $sale->calculateTakeoff($lineItem->price);
            }

            if($lineItem->salePrice < 0) {
                $lineItem->salePrice = 0;
            }
		} else {
			$lineItem->addError('variantId', 'variant not found');
		}

		return $lineItem;
	}

	/**
	 * @param Market_LineItemModel $lineItem
	 * @return int
	 */
	public function delete($lineItem)
	{
		return Market_LineItemRecord::model()->deleteByPk($lineItem->id);
	}

	/**
	 * @param int $orderId
	 * @return int
	 */
	public function deleteAllByOrderId($orderId)
	{
		return Market_LineItemRecord::model()->deleteAllByAttributes(['orderId' => $orderId]);
	}
}