<?php

namespace App\Exceptions;

/**
 * Usuario no premium alcanzó su límite diario de likes/super_likes — ver
 * features/premium/specs/spec.md → "Paywall al agotar likes del día".
 * Renderizada como 429 (mismo código ya usado en el proyecto para
 * throttling de auth, ver bootstrap/app.php) en vez del 400 genérico de
 * BusinessException, para que el frontend la distinga sin tener que
 * comparar el texto del mensaje.
 */
class LikeLimitExceededException extends BusinessException {}
