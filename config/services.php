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
    | FCM (Firebase Cloud Messaging) — ver features/notifications/specs/plan.md
    | → "FCM sin credenciales". `FcmService` solo envía si
    | `credentials_path` resuelve a un archivo real; mientras tanto es un
    | no-op registrado en logs. `FCM_CREDENTIALS_PATH` en .env es solo la
    | ruta RELATIVA dentro del disco privado (storage/app/private/...) —
    | nunca una ruta absoluta ni un archivo fuera de ese disco, que está
    | completamente excluido de git (mismo disco que usa Verification para
    | los documentos de identidad).
    */
    'fcm' => [
        'credentials_path' => env('FCM_CREDENTIALS_PATH')
            ? storage_path('app/private/'.env('FCM_CREDENTIALS_PATH'))
            : null,
    ],

];
