<?php
class Quafzi_ProductMultiStoreMassUpdate_AttributeController extends Mage_Adminhtml_Controller_Action
{
    public function saveAction()
    {
        if (!$this->_validateProducts()) {
            return;
        }

        /* Collect Data */
        $inventoryData      = $this->getRequest()->getParam('inventory', array());
        $attributesData     = $this->getRequest()->getParam('attributes', array());
        $websiteRemoveData  = $this->getRequest()->getParam('remove_website_ids', array());
        $websiteAddData     = $this->getRequest()->getParam('add_website_ids', array());

        /* Prepare inventory data item options (use config settings) */
        foreach (Mage::helper('cataloginventory')->getConfigItemOptions() as $option) {
            if (isset($inventoryData[$option]) && !isset($inventoryData['use_config_' . $option])) {
                $inventoryData['use_config_' . $option] = 0;
            }
        }

        try {
            $storeIds = explode(',', $this->getRequest()->getParam('stores'));
            foreach ($storeIds as $storeId) {
                $this->_updateAttributesData($attributesData, $storeId);
            }
            $this->_saveInventoryData($inventoryData);
            $this->_saveWebsiteData($websiteAddData, $websiteRemoveData);
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('An error occurred while updating the product(s) attributes.'));
        }

        $this->_redirect('adminhtml/catalog_product/', array('store'=>$this->_getHelper()->getSelectedStoreId()));
    }

    protected function _updateAttributesData($attributesData, $storeId)
    {
        if ($attributesData) {
            $dateFormat = Mage::app()->getLocale()->getDateFormat(Mage_Core_Model_Locale::FORMAT_TYPE_SHORT);

            foreach ($attributesData as $attributeCode => $value) {
                $attribute = Mage::getSingleton('eav/config')
                    ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
                if (!$attribute->getAttributeId()) {
                    unset($attributesData[$attributeCode]);
                    continue;
                }
                if ($attribute->getBackendType() == 'datetime') {
                    if (!empty($value)) {
                        $filterInput    = new Zend_Filter_LocalizedToNormalized(array(
                            'date_format' => $dateFormat
                        ));
                        $filterInternal = new Zend_Filter_NormalizedToLocalized(array(
                            'date_format' => Varien_Date::DATE_INTERNAL_FORMAT
                        ));
                        $value = $filterInternal->filter($filterInput->filter($value));
                    } else {
                        $value = null;
                    }
                    $attributesData[$attributeCode] = $value;
                } elseif ($attribute->getFrontendInput() == 'multiselect') {
                    // Check if 'Change' checkbox has been checked by admin for this attribute
                    $isChanged = (bool)$this->getRequest()->getPost($attributeCode . '_checkbox');
                    if (!$isChanged) {
                        unset($attributesData[$attributeCode]);
                        continue;
                    }
                    if (is_array($value)) {
                        $value = implode(',', $value);
                    }
                    $attributesData[$attributeCode] = $value;
                }
            }

            Mage::getSingleton('catalog/product_action')
                ->updateAttributes($this->_getHelper()->getProductIds(), $attributesData, $storeId);
        }
    }

    protected function _saveInventoryData($inventoryData)
    {
        if ($inventoryData) {
            /** @var $stockItem Mage_CatalogInventory_Model_Stock_Item */
            $stockItem = Mage::getModel('cataloginventory/stock_item');
            $stockItem->setProcessIndexEvents(false);
            $stockItemSaved = false;

            foreach ($this->_getHelper()->getProductIds() as $productId) {
                $stockItem->setData(array());
                $stockItem->loadByProduct($productId)
                    ->setProductId($productId);

                $stockDataChanged = false;
                foreach ($inventoryData as $k => $v) {
                    $stockItem->setDataUsingMethod($k, $v);
                    if ($stockItem->dataHasChangedFor($k)) {
                        $stockDataChanged = true;
                    }
                }
                if ($stockDataChanged) {
                    $stockItem->save();
                    $stockItemSaved = true;
                }
            }

            if ($stockItemSaved) {
                Mage::getSingleton('index/indexer')->indexEvents(
                    Mage_CatalogInventory_Model_Stock_Item::ENTITY,
                    Mage_Index_Model_Event::TYPE_SAVE
                );
            }
        }
    }

    protected function _saveWebsiteData($websiteAddData, $websiteRemoveData)
    {
        if ($websiteAddData || $websiteRemoveData) {
            /* @var $actionModel Mage_Catalog_Model_Product_Action */
            $actionModel = Mage::getSingleton('catalog/product_action');
            $productIds  = $this->_getHelper()->getProductIds();

            if ($websiteRemoveData) {
                $actionModel->updateWebsites($productIds, $websiteRemoveData, 'remove');
            }
            if ($websiteAddData) {
                $actionModel->updateWebsites($productIds, $websiteAddData, 'add');
            }

            /**
             * @deprecated since 1.3.2.2
             */
            Mage::dispatchEvent('catalog_product_to_website_change', array(
                'products' => $productIds
            ));

            $notice = Mage::getConfig()->getNode('adminhtml/messages/website_chnaged_indexers/label');
            if ($notice) {
                $this->_getSession()->addNotice($this->__((string)$notice, $this->getUrl('adminhtml/process/list')));
            }
        }

        $this->_getSession()->addSuccess(
            $this->__('Total of %d record(s) were updated', count($this->_getHelper()->getProductIds()))
        );
    }

    protected function _validateProducts()
    {
        $error = false;
        $productIds = $this->_getHelper()->getProductIds();
        if (!is_array($productIds)) {
            $error = $this->__('Please select products for attributes update');
        } else if (!Mage::getModel('catalog/product')->isProductsHasSku($productIds)) {
            $error = $this->__('Some of the processed products have no SKU value defined. Please fill it prior to performing operations on these products.');
        }

        if ($error) {
            $this->_getSession()->addError($error);
            $this->_redirect('*/catalog_product/', array('_current'=>true));
        }

        return !$error;
    }

    /**
     * Rertive data manipulation helper
     *
     * @return Mage_Adminhtml_Helper_Catalog_Product_Edit_Action_Attribute
     */
    protected function _getHelper()
    {
        return Mage::helper('adminhtml/catalog_product_edit_action_attribute');
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('catalog/update_attributes');
    }
}
