<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Expo Push Service — ver features/notifications/specs/plan.md →
    | "Migración a Expo Push Service". `ExpoPushService` llama a la API
    | pública de Expo (no requiere credenciales propias; Expo administra
    | las credenciales de FCM/APNs del lado de EAS). `access_token` es
    | opcional — solo aplica si se activa "Enhanced Security for Push
    | Notifications" en el dashboard de Expo.
    */
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    /*
    | RevenueCat — ver features/subscriptions/specs/plan.md → "Webhook de
    | RevenueCat". Secreto compartido que RevenueCat envía como
    | `Authorization: Bearer {secret}` al llamar
    | POST /api/webhooks/revenuecat. Placeholder vacío hasta que exista la
    | cuenta real de RevenueCat (ver spec.md → "Bloqueos externos
    | actuales") — con el valor vacío, `VerifyRevenueCatWebhookSecret`
    | rechaza cualquier request, nunca deja el webhook abierto sin secreto.
    */
    'revenuecat' => [
        'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
    ],

];
