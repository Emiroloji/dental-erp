<?php

namespace App\Domain\Access\Support;

enum Module: string
{
    case ProductManagement = 'product_management';
    case CategoryManagement = 'category_management';
    case SupplierManagement = 'supplier_management';
    case StockMovement = 'stock_movement';
    case Transfer = 'transfer';
    case Purchasing = 'purchasing';
    case StaffManagement = 'staff_management';
    case Reports = 'reports';
    case SystemSettings = 'system_settings';
    case Finance = 'finance';
    case Patients = 'patients';

    public function label(): string
    {
        return match ($this) {
            self::ProductManagement => 'Ürün Yönetimi',
            self::CategoryManagement => 'Kategori Yönetimi',
            self::SupplierManagement => 'Tedarikçi Yönetimi',
            self::StockMovement => 'Stok Giriş/Çıkış',
            self::Transfer => 'Şubeler Arası Talep/Transfer (Onay dahil)',
            self::Purchasing => 'Satın Alma (onay yalnızca Admin)',
            self::StaffManagement => 'Personel Yönetimi',
            self::Reports => 'Raporlar',
            self::SystemSettings => 'Sistem Ayarları',
            self::Finance => 'Finans (yalnızca tüm şubeler kapsamı)',
            self::Patients => 'Hastalar (kimlik bilgisi içerir)',
        };
    }
}
