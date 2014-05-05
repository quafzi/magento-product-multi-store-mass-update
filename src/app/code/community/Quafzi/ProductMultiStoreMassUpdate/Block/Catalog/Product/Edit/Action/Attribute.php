<?php
class Quafzi_ProductMultiStoreMassUpdate_Block_Catalog_Product_Edit_Action_Attribute
    extends Mage_Adminhtml_Block_Catalog_Product_Edit_Action_Attribute
{
    public function getSaveUrl()
    {
        $stores = $this->getRequest()->getParam('stores');
        if (!$stores) {
            return parent::getSaveUrl();
        }
        return $this->getUrl(
            'productmultistoremassupdate/attribute/save',
            array('stores'=>$stores)
        );
    }
}
