<?php

/*
| Reglas de negocio de la tienda (configurables por .env).
*/
return [
    // IVA de El Salvador
    'tax_rate' => (float) env('SHOP_TAX_RATE', 0.13),

    // Minutos que una orden puede quedar "pending" antes de cancelarse y liberar el stock
    'pending_order_minutes' => (int) env('SHOP_PENDING_ORDER_MINUTES', 30),

    // Máximo de órdenes "pending" simultáneas por usuario (evita acaparar inventario)
    'max_pending_orders_per_user' => (int) env('SHOP_MAX_PENDING_ORDERS', 5),
];
