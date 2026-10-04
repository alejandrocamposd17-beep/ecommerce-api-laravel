<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza las imágenes aleatorias de picsum.photos por ilustraciones propias
 * de cada producto (public/images/products). Conserva órdenes y usuarios existentes.
 * En una instalación nueva no hay productos aún: el seeder ya trae las rutas nuevas.
 */
return new class extends Migration
{
    private const IMAGES = [
        'Laptop Lenovo IdeaPad 3' => 'laptop',
        'MacBook Air M2' => 'macbook',
        'Monitor Samsung 24" FHD' => 'monitor',
        'Teclado mecánico Redragon K552' => 'keyboard',
        'Mouse inalámbrico Logitech M170' => 'mouse',
        'Audífonos Sony WH-1000XM5' => 'headphones',
        'Bocina JBL Flip 6' => 'speaker',
        'Smartphone Samsung Galaxy A54' => 'phone',
        'iPhone 15' => 'iphone',
        'Tablet Samsung Galaxy Tab A9' => 'tablet',
        'Webcam Logitech C920' => 'webcam',
        'Disco SSD Kingston 1TB NVMe' => 'ssd',
        'Memoria USB SanDisk 128GB' => 'usb',
        'Router TP-Link Archer AX23' => 'router',
        'Silla gamer Cougar Armor One' => 'chair',
        'Impresora Epson EcoTank L3250' => 'printer',
        'Smartwatch Xiaomi Redmi Watch 4' => 'watch',
        'Cargador rápido Anker 65W GaN' => 'charger',
        'Consola Nintendo Switch OLED' => 'switch',
        'Control inalámbrico Xbox' => 'controller',
    ];

    public function up(): void
    {
        foreach (self::IMAGES as $name => $key) {
            DB::table('products')->where('name', $name)->update(['image_url' => "/images/products/{$key}.svg"]);
        }
    }

    public function down(): void
    {
        foreach (self::IMAGES as $name => $key) {
            DB::table('products')->where('name', $name)->update(['image_url' => "https://picsum.photos/seed/{$key}/600/400"]);
        }
    }
};
