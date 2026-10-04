<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Laptop Lenovo IdeaPad 3', 'description' => 'Laptop 15.6" Ryzen 5, 8GB RAM, 512GB SSD, Windows 11.', 'price' => 549.99, 'stock' => 15, 'category' => 'Computadoras', 'image_url' => '/images/products/laptop.svg'],
            ['name' => 'MacBook Air M2', 'description' => 'Apple MacBook Air 13" chip M2, 8GB RAM, 256GB SSD.', 'price' => 1099.00, 'stock' => 8, 'category' => 'Computadoras', 'image_url' => '/images/products/macbook.svg'],
            ['name' => 'Monitor Samsung 24" FHD', 'description' => 'Monitor LED 24 pulgadas Full HD 75Hz, HDMI y VGA.', 'price' => 139.99, 'stock' => 25, 'category' => 'Monitores', 'image_url' => '/images/products/monitor.svg'],
            ['name' => 'Teclado mecánico Redragon K552', 'description' => 'Teclado mecánico RGB switches Outemu Red, layout en español.', 'price' => 39.99, 'stock' => 40, 'category' => 'Accesorios', 'image_url' => '/images/products/keyboard.svg'],
            ['name' => 'Mouse inalámbrico Logitech M170', 'description' => 'Mouse compacto con conexión USB 2.4 GHz y 12 meses de batería.', 'price' => 14.99, 'stock' => 60, 'category' => 'Accesorios', 'image_url' => '/images/products/mouse.svg'],
            ['name' => 'Audífonos Sony WH-1000XM5', 'description' => 'Audífonos inalámbricos con cancelación de ruido y 30h de batería.', 'price' => 349.99, 'stock' => 12, 'category' => 'Audio', 'image_url' => '/images/products/headphones.svg'],
            ['name' => 'Bocina JBL Flip 6', 'description' => 'Bocina Bluetooth portátil resistente al agua IP67.', 'price' => 129.95, 'stock' => 30, 'category' => 'Audio', 'image_url' => '/images/products/speaker.svg'],
            ['name' => 'Smartphone Samsung Galaxy A54', 'description' => 'Pantalla 6.4" AMOLED, 128GB, cámara 50MP, 5G.', 'price' => 379.00, 'stock' => 20, 'category' => 'Celulares', 'image_url' => '/images/products/phone.svg'],
            ['name' => 'iPhone 15', 'description' => 'Apple iPhone 15 128GB, chip A16 Bionic, USB-C.', 'price' => 829.00, 'stock' => 10, 'category' => 'Celulares', 'image_url' => '/images/products/iphone.svg'],
            ['name' => 'Tablet Samsung Galaxy Tab A9', 'description' => 'Tablet 8.7" 64GB WiFi, ideal para estudio y entretenimiento.', 'price' => 159.00, 'stock' => 18, 'category' => 'Tablets', 'image_url' => '/images/products/tablet.svg'],
            ['name' => 'Webcam Logitech C920', 'description' => 'Cámara web Full HD 1080p con micrófono estéreo.', 'price' => 69.99, 'stock' => 35, 'category' => 'Accesorios', 'image_url' => '/images/products/webcam.svg'],
            ['name' => 'Disco SSD Kingston 1TB NVMe', 'description' => 'SSD NVMe PCIe Gen4 con velocidades de hasta 7000 MB/s.', 'price' => 89.99, 'stock' => 50, 'category' => 'Almacenamiento', 'image_url' => '/images/products/ssd.svg'],
            ['name' => 'Memoria USB SanDisk 128GB', 'description' => 'Memoria USB 3.0 Ultra Flair de 128GB.', 'price' => 16.50, 'stock' => 100, 'category' => 'Almacenamiento', 'image_url' => '/images/products/usb.svg'],
            ['name' => 'Router TP-Link Archer AX23', 'description' => 'Router WiFi 6 doble banda AX1800 con 4 antenas.', 'price' => 74.99, 'stock' => 22, 'category' => 'Redes', 'image_url' => '/images/products/router.svg'],
            ['name' => 'Silla gamer Cougar Armor One', 'description' => 'Silla ergonómica reclinable con soporte lumbar y cervical.', 'price' => 199.00, 'stock' => 7, 'category' => 'Mobiliario', 'image_url' => '/images/products/chair.svg'],
            ['name' => 'Impresora Epson EcoTank L3250', 'description' => 'Impresora multifuncional inalámbrica con sistema de tinta continua.', 'price' => 229.00, 'stock' => 9, 'category' => 'Impresoras', 'image_url' => '/images/products/printer.svg'],
            ['name' => 'Smartwatch Xiaomi Redmi Watch 4', 'description' => 'Reloj inteligente pantalla AMOLED 1.97", GPS y 20 días de batería.', 'price' => 79.99, 'stock' => 28, 'category' => 'Wearables', 'image_url' => '/images/products/watch.svg'],
            ['name' => 'Cargador rápido Anker 65W GaN', 'description' => 'Cargador USB-C de 65W compatible con laptops y celulares.', 'price' => 34.99, 'stock' => 45, 'category' => 'Accesorios', 'image_url' => '/images/products/charger.svg'],
            ['name' => 'Consola Nintendo Switch OLED', 'description' => 'Consola híbrida con pantalla OLED de 7" y 64GB.', 'price' => 349.99, 'stock' => 6, 'category' => 'Videojuegos', 'image_url' => '/images/products/switch.svg'],
            ['name' => 'Control inalámbrico Xbox', 'description' => 'Control Bluetooth compatible con Xbox, PC y móviles.', 'price' => 59.99, 'stock' => 33, 'category' => 'Videojuegos', 'image_url' => '/images/products/controller.svg'],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(['name' => $data['name']], $data + ['is_active' => true]);
        }
    }
}
