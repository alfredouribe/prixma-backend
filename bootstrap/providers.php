<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    // Telescope es un paquete de require-dev — con `composer install --no-dev`
    // (cualquier entorno de producción real) no existe, y TelescopeServiceProvider
    // extiende una clase suya, así que registrarlo sin condición rompe el arranque
    // completo de la app. Detectado 2026-09-20 desplegando por primera vez a un
    // VPS real (nunca se había probado un install --no-dev antes de esta sesión).
    ...(class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)
        ? [App\Providers\TelescopeServiceProvider::class]
        : []),
];
