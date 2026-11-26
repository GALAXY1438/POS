<?php
/**
 * StoreHelper - Multi-Tenant Helper Class
 * 
 * Provides methods to automatically filter queries by store_id
 * Makes it easy to ensure all queries are store-aware
 */
class StoreHelper {
    
    /**
     * Check if multi-tenant mode is enabled
     */
    public static function isMultiTenant() {
        static $isMultiTenant = null;
        
        if ($isMultiTenant === null) {
            try {
                $conn = Database::getInstance()->getConnection();
                $result = $conn->query("SHOW TABLES LIKE 'stores'");
                $isMultiTenant = $result->rowCount() > 0;
            } catch (Exception $e) {
                $isMultiTenant = false;
            }
        }
        
        return $isMultiTenant;
    }
    
    /**
     * Get current user's store_id from session
     */
    public static function getCurrentStoreId() {
        return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
    }
    
    /**
     * Get store filter SQL for WHERE clause
     * Usage: "SELECT * FROM products WHERE 1=1 " . StoreHelper::getStoreFilter()
     */
    public static function getStoreFilter($tableAlias = '') {
        if (!self::isMultiTenant()) {
            return ''; // Single tenant - no filter needed
        }
        
        $storeId = self::getCurrentStoreId();
        if (!$storeId) {
            return ' AND 1=0'; // No store_id in session - return no results
        }
        
        $prefix = $tableAlias ? $tableAlias . '.' : '';
        return " AND {$prefix}store_id = {$storeId}";
    }
    
    /**
     * Get store filter as array for PDO binding
     * Usage: $params = array_merge($yourParams, StoreHelper::getStoreFilterParams());
     */
    public static function getStoreFilterParams() {
        if (!self::isMultiTenant()) {
            return [];
        }
        
        $storeId = self::getCurrentStoreId();
        return $storeId ? ['store_id' => $storeId] : [];
    }
    
    /**
     * Add store_id to INSERT data
     * Usage: $data = StoreHelper::addStoreId($data);
     */
    public static function addStoreId($data) {
        if (!self::isMultiTenant()) {
            return $data;
        }
        
        $storeId = self::getCurrentStoreId();
        if ($storeId) {
            $data['store_id'] = $storeId;
        }
        
        return $data;
    }
    
    /**
     * Get store information
     */
    public static function getStoreInfo($storeId = null) {
        if (!self::isMultiTenant()) {
            return null;
        }
        
        $storeId = $storeId ?: self::getCurrentStoreId();
        if (!$storeId) {
            return null;
        }
        
        try {
            $conn = Database::getInstance()->getConnection();
            $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ?");
            $stmt->execute([$storeId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if user owns the store (is the store owner/admin)
     */
    public static function isStoreOwner($userId = null) {
        if (!self::isMultiTenant()) {
            return false;
        }
        
        $userId = $userId ?: (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
        $storeId = self::getCurrentStoreId();
        
        if (!$userId || !$storeId) {
            return false;
        }
        
        try {
            $conn = Database::getInstance()->getConnection();
            $stmt = $conn->prepare("SELECT owner_id FROM stores WHERE id = ?");
            $stmt->execute([$storeId]);
            $store = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $store && $store['owner_id'] == $userId;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get store setting value
     */
    public static function getStoreSetting($key, $default = null) {
        $storeId = self::getCurrentStoreId();
        if (!$storeId) {
            return $default;
        }
        
        try {
            $conn = Database::getInstance()->getConnection();
            $stmt = $conn->prepare("SELECT setting_value FROM store_settings WHERE store_id = ? AND setting_key = ?");
            $stmt->execute([$storeId, $key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['setting_value'] : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}
?>
