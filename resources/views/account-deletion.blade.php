<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Eliminar tu cuenta — Prixma</title>
        <meta name="description" content="Cómo solicitar la eliminación de tu cuenta de Prixma y qué pasa con tus datos.">

        <style>
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-Regular.ttf') format('truetype');
                font-weight: 400;
                font-display: swap;
            }
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-Medium.ttf') format('truetype');
                font-weight: 500;
                font-display: swap;
            }
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-SemiBold.ttf') format('truetype');
                font-weight: 600;
                font-display: swap;
            }
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-Bold.ttf') format('truetype');
                font-weight: 700;
                font-display: swap;
            }

            :root {
                --dark: #0d0d14;
                --purple: #9b5dff;
                --rose: #ff5e7d;
                --text-secondary: rgba(255, 255, 255, 0.65);
                --text-tertiary: rgba(255, 255, 255, 0.45);
                --card-border: rgba(255, 255, 255, 0.08);
                --card-bg: rgba(255, 255, 255, 0.03);
            }

            * {
                box-sizing: border-box;
            }

            html, body {
                margin: 0;
                padding: 0;
            }

            body {
                background: var(--dark);
                color: #ffffff;
                font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif;
                min-height: 100vh;
                padding: 48px 24px 64px;
                position: relative;
            }

            .glow {
                position: fixed;
                width: 560px;
                height: 560px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(155, 93, 255, 0.25) 0%, rgba(155, 93, 255, 0) 70%);
                top: -120px;
                right: -160px;
                pointer-events: none;
                z-index: 0;
            }

            main {
                position: relative;
                z-index: 1;
                max-width: 560px;
                margin: 0 auto;
            }

            .logo {
                width: 140px;
                height: auto;
                margin-bottom: 32px;
            }

            h1 {
                font-size: 26px;
                font-weight: 700;
                margin: 0 0 8px;
                line-height: 1.3;
            }

            p.lead {
                font-size: 15px;
                line-height: 1.6;
                color: var(--text-secondary);
                margin: 0 0 36px;
            }

            .card {
                background: var(--card-bg);
                border: 1px solid var(--card-border);
                border-radius: 16px;
                padding: 24px;
                margin-bottom: 20px;
            }

            .card h2 {
                font-size: 15px;
                font-weight: 600;
                margin: 0 0 6px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .card .step-number {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: var(--purple);
                color: #fff;
                font-size: 12px;
                font-weight: 700;
                flex-shrink: 0;
            }

            .card p {
                font-size: 14px;
                line-height: 1.6;
                color: var(--text-secondary);
                margin: 0;
            }

            .card a {
                color: var(--purple);
                text-decoration: none;
                font-weight: 500;
            }

            .card a:hover {
                text-decoration: underline;
            }

            section.what {
                margin-top: 40px;
            }

            section.what h2 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 14px;
            }

            ul.data-list {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            ul.data-list li {
                font-size: 14px;
                line-height: 1.6;
                color: var(--text-secondary);
                padding-left: 22px;
                position: relative;
            }

            ul.data-list li::before {
                content: '';
                position: absolute;
                left: 0;
                top: 8px;
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--purple);
            }

            ul.data-list li.kept::before {
                background: var(--text-tertiary);
            }

            .note {
                margin-top: 28px;
                font-size: 13px;
                line-height: 1.6;
                color: var(--text-tertiary);
            }

            footer {
                max-width: 560px;
                margin: 56px auto 0;
                font-size: 12px;
                color: var(--text-tertiary);
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="glow"></div>

        <main>
            <img class="logo" src="/prixma_resources/png/logo_full_white.png" alt="Prixma">

            <h1>Eliminar tu cuenta</h1>
            <p class="lead">
                Puedes pedir que tu cuenta y tus datos personales se eliminen de Prixma en cualquier momento. Así funciona.
            </p>

            <div class="card">
                <h2><span class="step-number">1</span> Desde la app (inmediato)</h2>
                <p>
                    Abre Prixma → <strong>Mi perfil → Configuración → Eliminar cuenta</strong>.
                    Tu cuenta se elimina al instante y se cierra sesión en todos tus dispositivos.
                </p>
            </div>

            <div class="card">
                <h2><span class="step-number">2</span> Si ya no tienes la app instalada</h2>
                <p>
                    Escríbenos a <a href="mailto:privacidad@prixma.site">privacidad@prixma.site</a> desde el correo asociado a tu cuenta, indicando que quieres eliminarla.
                    Procesaremos tu solicitud en un plazo máximo de 20 días hábiles.
                </p>
            </div>

            <section class="what">
                <h2>¿Qué pasa con tus datos?</h2>
                <ul class="data-list">
                    <li>Tu perfil, fotos, video de presentación y documentos de verificación se eliminan de forma permanente.</li>
                    <li>Se cierran todas tus sesiones activas de inmediato.</li>
                    <li class="kept">Los mensajes que ya enviaste a otras personas se conservan del lado de elles — igual que cuando bloqueas a alguien, no borramos conversaciones ajenas.</li>
                </ul>
            </section>

            <p class="note">
                Para más detalle sobre qué información recopilamos y tus derechos ARCO (Acceder, Rectificar, Cancelar u Oponerte), consulta nuestro <a href="https://prixma.site/privacidad" style="color: var(--text-secondary);">Aviso de Privacidad</a>.
            </p>
        </main>

        <footer>&copy; {{ date('Y') }} Prixma</footer>
    </body>
</html>
