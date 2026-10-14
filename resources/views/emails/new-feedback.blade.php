<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo feedback en Prixma</title>
</head>
<body style="font-family: sans-serif; font-size: 14px; color: #222; line-height: 1.6;">
    <p><strong>Tipo:</strong> {{ $feedback->type === 'bug' ? 'Bug' : 'Comentario' }}</p>

    <p><strong>De:</strong>
        {{ $feedback->user?->profile?->display_name ?? 'Sin nombre' }}
        ({{ $feedback->user?->email ?? 'correo desconocido' }})
    </p>

    <p><strong>Versión de app:</strong> {{ $feedback->app_version ?? 'No especificada' }}</p>
    <p><strong>Plataforma:</strong> {{ $feedback->platform ?? 'No especificada' }}</p>
    <p><strong>Fecha:</strong> {{ $feedback->created_at->format('d/m/Y H:i') }}</p>

    <p><strong>Mensaje:</strong></p>
    <p style="white-space: pre-wrap; background: #f5f5f5; padding: 12px; border-radius: 6px;">{{ $feedback->message }}</p>
</body>
</html>
